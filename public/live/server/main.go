// Pioneer : WHIP/WHEP relay for 6chan's moderator-only livestream.
//
// One mod publishes (WHIP), any number of anonymous viewers subscribe (WHEP).
// Only one stream can be live at a time — a second WHIP publish is rejected
// with 409 until the first one ends.
//
// ENV vars:
//   PIONEER_SECRET  : shared HMAC secret with the PHP side (required)
//   PIONEER_ADDR    : listen address (default ":8088")

package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"
	"io"

	"github.com/pion/webrtc/v4"
	"github.com/joho/godotenv"
)

type liveStream struct {
	mu          sync.RWMutex
	live        bool
	title       string
	startedAt   time.Time
	publisherPC *webrtc.PeerConnection

	videoTrack *webrtc.TrackLocalStaticRTP
	audioTrack *webrtc.TrackLocalStaticRTP

	viewers   map[*webrtc.PeerConnection]struct{}
	viewersMu sync.Mutex
}

var stream = &liveStream{viewers: make(map[*webrtc.PeerConnection]struct{})}

func (s *liveStream) status() map[string]any {
	s.mu.RLock()
	defer s.mu.RUnlock()
	s.viewersMu.Lock()
	viewerCount := len(s.viewers)
	s.viewersMu.Unlock()
	return map[string]any{
		"live":      s.live,
		"title":     s.title,
		"startedAt": s.startedAt.UTC().Format(time.RFC3339),
		"viewers":   viewerCount,
	}
}

// HMAC mod token
// PHP mints: token = base64url( "<modID>.<expiryUnix>." + hex(hmac_sha256(secret, "<modID>.<expiryUnix>")) )
// Sent as: Authorization: Bearer <token>

func verifyToken(secret, token string) (modID string, err error) {
	raw, err := base64.RawURLEncoding.DecodeString(token)
	if err != nil {
		return "", errors.New("bad token encoding")
	}
	parts := strings.SplitN(string(raw), ".", 3)
	if len(parts) != 3 {
		return "", errors.New("malformed token")
	}
	modID, expiryStr, sig := parts[0], parts[1], parts[2]

	expiry, err := strconv.ParseInt(expiryStr, 10, 64)
	if err != nil {
		return "", errors.New("bad expiry")
	}
	if time.Now().Unix() > expiry {
		return "", errors.New("token expired")
	}

	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(modID + "." + expiryStr))
	expected := hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(sig), []byte(expected)) {
		return "", errors.New("bad signature")
	}
	return modID, nil
}

func handleWHIP(secret string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}

		authz := r.Header.Get("Authorization")
		token, ok := strings.CutPrefix(authz, "Bearer ")
		if !ok {
			http.Error(w, "missing bearer token", http.StatusUnauthorized)
			return
		}
		modID, err := verifyToken(secret, token)
		if err != nil {
			http.Error(w, "invalid token: "+err.Error(), http.StatusUnauthorized)
			return
		}

		stream.mu.Lock()
		if stream.live {
			stream.mu.Unlock()
			http.Error(w, "a stream is already live", http.StatusConflict)
			return
		}
		stream.mu.Unlock()

		body, err := readSDPOffer(r)
		if err != nil {
			http.Error(w, err.Error(), http.StatusBadRequest)
			return
		}

		pc, err := webrtc.NewPeerConnection(webrtc.Configuration{
			ICEServers: []webrtc.ICEServer{{URLs: []string{"stun:stun.l.google.com:19302"}}},
		})
		if err != nil {
			http.Error(w, "pc setup failed", http.StatusInternalServerError)
			return
		}

		// Publisher only sends — we don't need to receive back.
		if _, err := pc.AddTransceiverFromKind(webrtc.RTPCodecTypeVideo, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionRecvonly}); err != nil {
			http.Error(w, "transceiver setup failed", http.StatusInternalServerError)
			return
		}
		if _, err := pc.AddTransceiverFromKind(webrtc.RTPCodecTypeAudio, webrtc.RTPTransceiverInit{Direction: webrtc.RTPTransceiverDirectionRecvonly}); err != nil {
			http.Error(w, "transceiver setup failed", http.StatusInternalServerError)
			return
		}

		pc.OnTrack(func(remote *webrtc.TrackRemote, _ *webrtc.RTPReceiver) {
			var local *webrtc.TrackLocalStaticRTP
			var err error

			if remote.Kind() == webrtc.RTPCodecTypeVideo {
				local, err = webrtc.NewTrackLocalStaticRTP(remote.Codec().RTPCodecCapability, "video", "pioneer")
				stream.mu.Lock()
				stream.videoTrack = local
				stream.mu.Unlock()
			} else {
				local, err = webrtc.NewTrackLocalStaticRTP(remote.Codec().RTPCodecCapability, "audio", "pioneer")
				stream.mu.Lock()
				stream.audioTrack = local
				stream.mu.Unlock()
			}
			if err != nil {
				log.Println("local track create failed:", err)
				return
			}

			buf := make([]byte, 1500)
			for {
				n, _, readErr := remote.Read(buf)
				if readErr != nil {
					return
				}
				if _, writeErr := local.Write(buf[:n]); writeErr != nil && !errors.Is(writeErr, io.ErrClosedPipe) {
					return
				}
			}
		})

		pc.OnConnectionStateChange(func(state webrtc.PeerConnectionState) {
			if state == webrtc.PeerConnectionStateFailed || state == webrtc.PeerConnectionStateClosed || state == webrtc.PeerConnectionStateDisconnected {
				endStream()
			}
		})

		if err := pc.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeOffer, SDP: body}); err != nil {
			http.Error(w, "bad SDP", http.StatusBadRequest)
			return
		}
		answer, err := pc.CreateAnswer(nil)
		if err != nil {
			http.Error(w, "answer failed", http.StatusInternalServerError)
			return
		}
		gatherComplete := webrtc.GatheringCompletePromise(pc)
		if err := pc.SetLocalDescription(answer); err != nil {
			http.Error(w, "set local desc failed", http.StatusInternalServerError)
			return
		}
		<-gatherComplete

		stream.mu.Lock()
		stream.live = true
		stream.title = r.URL.Query().Get("title")
		stream.startedAt = time.Now()
		stream.publisherPC = pc
		stream.mu.Unlock()

		log.Printf("mod %s went live (title=%q)", modID, stream.title)

		w.Header().Set("Content-Type", "application/sdp")
		w.Header().Set("Location", "/whip")
		w.WriteHeader(http.StatusCreated)
		w.Write([]byte(pc.LocalDescription().SDP))
	}
}

func endStream() {
	stream.mu.Lock()
	if stream.publisherPC != nil {
		stream.publisherPC.Close()
	}
	stream.live = false
	stream.publisherPC = nil
	stream.videoTrack = nil
	stream.audioTrack = nil
	stream.mu.Unlock()

	stream.viewersMu.Lock()
	for pc := range stream.viewers {
		pc.Close()
	}
	stream.viewers = make(map[*webrtc.PeerConnection]struct{})
	stream.viewersMu.Unlock()

	log.Println("stream ended")
}

// mod calls DELETE /whip with the same bearer token to end the stream early
func handleWHIPEnd(secret string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodDelete {
			http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
			return
		}
		authz := r.Header.Get("Authorization")
		token, ok := strings.CutPrefix(authz, "Bearer ")
		if !ok {
			http.Error(w, "missing bearer token", http.StatusUnauthorized)
			return
		}
		if _, err := verifyToken(secret, token); err != nil {
			http.Error(w, "invalid token", http.StatusUnauthorized)
			return
		}
		endStream()
		w.WriteHeader(http.StatusOK)
	}
}

func handleWHEP(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	stream.mu.RLock()
	live := stream.live
	video, audio := stream.videoTrack, stream.audioTrack
	stream.mu.RUnlock()
	if !live || video == nil {
		http.Error(w, "no stream live", http.StatusNotFound)
		return
	}

	body, err := readSDPOffer(r)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	pc, err := webrtc.NewPeerConnection(webrtc.Configuration{
		ICEServers: []webrtc.ICEServer{{URLs: []string{"stun:stun.l.google.com:19302"}}},
	})
	if err != nil {
		http.Error(w, "pc setup failed", http.StatusInternalServerError)
		return
	}

	if _, err := pc.AddTrack(video); err != nil {
		http.Error(w, "add video track failed", http.StatusInternalServerError)
		return
	}
	if audio != nil {
		if _, err := pc.AddTrack(audio); err != nil {
			http.Error(w, "add audio track failed", http.StatusInternalServerError)
			return
		}
	}

	pc.OnConnectionStateChange(func(state webrtc.PeerConnectionState) {
		if state == webrtc.PeerConnectionStateFailed || state == webrtc.PeerConnectionStateClosed || state == webrtc.PeerConnectionStateDisconnected {
			stream.viewersMu.Lock()
			delete(stream.viewers, pc)
			stream.viewersMu.Unlock()
		}
	})

	if err := pc.SetRemoteDescription(webrtc.SessionDescription{Type: webrtc.SDPTypeOffer, SDP: body}); err != nil {
		http.Error(w, "bad SDP", http.StatusBadRequest)
		return
	}
	answer, err := pc.CreateAnswer(nil)
	if err != nil {
		http.Error(w, "answer failed", http.StatusInternalServerError)
		return
	}
	gatherComplete := webrtc.GatheringCompletePromise(pc)
	if err := pc.SetLocalDescription(answer); err != nil {
		http.Error(w, "set local desc failed", http.StatusInternalServerError)
		return
	}
	<-gatherComplete

	stream.viewersMu.Lock()
	stream.viewers[pc] = struct{}{}
	stream.viewersMu.Unlock()

	w.Header().Set("Content-Type", "application/sdp")
	w.WriteHeader(http.StatusCreated)
	w.Write([]byte(pc.LocalDescription().SDP))
}

func readSDPOffer(r *http.Request) (string, error) {
	defer r.Body.Close()
	buf := make([]byte, 0, 4096)
	tmp := make([]byte, 4096)
	for {
		n, err := r.Body.Read(tmp)
		buf = append(buf, tmp[:n]...)
		if err != nil {
			break
		}
	}
	if len(buf) == 0 {
		return "", errors.New("empty SDP body")
	}
	return string(buf), nil
}

func handleStatus(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Access-Control-Allow-Origin", "*")
	json.NewEncoder(w).Encode(stream.status())
}

func withCORS(h http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Access-Control-Allow-Origin", "*")
		w.Header().Set("Access-Control-Allow-Headers", "Authorization, Content-Type")
		w.Header().Set("Access-Control-Allow-Methods", "GET, POST, DELETE, OPTIONS")
		if r.Method == http.MethodOptions {
			w.WriteHeader(http.StatusOK)
			return
		}
		h(w, r)
	}
}

func main() {
	godotenv.Load("../.env")
	secret := os.Getenv("PIONEER_SECRET")
	if secret == "" {
		log.Fatal("PIONEER_SECRET env var is required")
	}
	addr := os.Getenv("PIONEER_ADDR")
	if addr == "" {
		addr = ":8088"
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/whip", withCORS(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == http.MethodDelete {
			handleWHIPEnd(secret)(w, r)
			return
		}
		handleWHIP(secret)(w, r)
	}))
	mux.HandleFunc("/whep", withCORS(handleWHEP))
	mux.HandleFunc("/status", withCORS(handleStatus))

	fmt.Println("Pioneer relay listening on", addr)
	log.Fatal(http.ListenAndServe(addr, mux))
}
