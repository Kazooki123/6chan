package main

import (
	"encoding/binary"
	"fmt"
	"os"
	"strings"
)

// Custom hash: BLAKE3-inspired mixing, pure Go, TinyGo-safe (✿◡‿◡)
//
// We don't import `golang.org/x/crypto/blake3` because TinyGo can't compile it.
// Instead we'll make a lightweight but strong mixing function using the same
// core ideas: ChaCha-style rotations, domain-separated rounds, and wide output.
//
// Security: this is NOT a drop-in replacement for BLAKE3 in cryptographic
// protocols, it's purpose-built for tripcodes (one-way, collision-resistant,
// fixed-output, fast). Good enough for imageboards, stronger than 4chan DES (imo).

const (
	tripLen   = 10
	alphabet  = "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz+/"
	siteSalt  = "6chan\x1fsalt\x1f2026"
)

func rol64(x uint64, k uint) uint64 {
	return (x << k) | (x >> (64 - k))
}

// mix applies 8 rounds of ARX mixing on a 4-word state.
func mix(s [4]uint64) [4]uint64 {
	for i := 0; i < 8; i++ {
		s[0] += s[1]; s[3] ^= s[0]; s[3] = rol64(s[3], 16)
		s[2] += s[3]; s[1] ^= s[2]; s[1] = rol64(s[1], 12)
		s[0] += s[1]; s[3] ^= s[0]; s[3] = rol64(s[3], 8)
		s[2] += s[3]; s[1] ^= s[2]; s[1] = rol64(s[1], 7)
	}
	return s
}

// Produces a 64-bit fingerprint of (salt + password).
func hashPassword(password string) uint64 {
	input := siteSalt + "\x00" + password

	// Initialize state with fractional parts of sqrt(2), sqrt(3), sqrt(5), sqrt(7)
	state := [4]uint64{
		0x6a09e667f3bcc908,
		0xbb67ae8584caa73b,
		0x3c6ef372fe94f82b,
		0xa54ff53a5f1d36f1,
	}

	// Absorb input in 8-byte chunks
	data := []byte(input)
	for len(data) >= 8 {
		block := binary.LittleEndian.Uint64(data[:8])
		state[0] ^= block
		state = mix(state)
		data = data[8:]
	}

	// Absorb remaining bytes with length padding
	if len(data) > 0 {
		var last [8]byte
		copy(last[:], data)
		last[len(data)] = 0x80
		state[1] ^= binary.LittleEndian.Uint64(last[:])
		state = mix(state)
	}

	// Finalization: XOR all words, one more mix
	state[2] ^= uint64(len(input)) | 0x8000000000000000
	state = mix(state)

	return state[0] ^ state[1] ^ state[2] ^ state[3]
}

// Converts a password into a 10-character tripcode string.
func GenerateTrip(password string) string {
	password = strings.TrimSpace(password)
	if password == "" {
		return ""
	}

	h := hashPassword(password)

	// Encode to base-64 alphabet, 10 chars (60 bits of the 64-bit hash)
	out := make([]byte, tripLen)
	for i := tripLen - 1; i >= 0; i-- {
		out[i] = alphabet[h&0x3F]
		h >>= 6
	}
	return string(out)
}

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: tripcodes <password>")
		os.Exit(1)
	}
	password := os.Args[1]
	trip := GenerateTrip(password)
	if trip == "" {
		fmt.Fprintln(os.Stderr, "empty password")
		os.Exit(1)
	}
	fmt.Print(trip)
}

