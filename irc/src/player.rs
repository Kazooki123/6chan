use std::fs::File;
use std::io::BufReader;
use std::path::Path;

use anyhow::{Context, Result};
use rodio::{Decoder, OutputStream, OutputStreamHandle, Sink};

pub struct Player {
    _stream: OutputStream,
    handle: OutputStreamHandle,
    sink: Option<Sink>,
    pub now_playing: Option<String>,
}

impl Player {
    pub fn new() -> Result<Self> {
        let (stream, handle) = OutputStream::try_default().context("no default audio output device")?;
        Ok(Self { _stream: stream, handle, sink: None, now_playing: None })
    }

    pub fn play(&mut self, path: &Path) -> Result<()> {
        let file = File::open(path).with_context(|| format!("opening {}", path.display()))?;
        let source = Decoder::new(BufReader::new(file))
            .with_context(|| format!("decoding {} (unsupported format?)", path.display()))?;
        let sink = Sink::try_new(&self.handle)?;
        sink.append(source);
        self.sink = Some(sink);
        self.now_playing = path.file_name().map(|f| f.to_string_lossy().to_string());
        Ok(())
    }

    pub fn pause(&self) {
        if let Some(s) = &self.sink {
            s.pause();
        }
    }

    pub fn resume(&self) {
        if let Some(s) = &self.sink {
            s.play();
        }
    }

    pub fn stop(&mut self) {
        self.sink = None; // dropping the Sink stops playback
        self.now_playing = None;
    }

    pub fn set_volume(&self, percent: u32) {
        if let Some(s) = &self.sink {
            s.set_volume(percent.min(200) as f32 / 100.0);
        }
    }
}
