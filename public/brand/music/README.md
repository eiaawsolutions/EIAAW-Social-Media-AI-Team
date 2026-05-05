# EIAAW background music library

These files back every video produced by `App\Services\Branding\BrandVideoComposer`
when the brand is EIAAW-internal (see `App\Services\Imagery\EiaawBrandLock`).

## Selection rule

`BrandVideoComposer::pickMusic()` hashes the draft id (CRC32) modulo the
number of files in this directory and picks the matching slot. Same draft
always gets the same music. Re-running media generation on a draft also
gets the same music — so a redraft doesn't surprise the operator with a
different soundscape.

## Tonal contract

Every track must:

- Be **instrumental** (no vocals — voiceover sits in the vocal range)
- Sit in the **warm-editorial** spirit of EIAAW's house style (Monocle film
  features as the reference): acoustic guitar, soft piano, ambient strings,
  textured warm pad. **No** hyped trap drops, no cinematic risers, no
  EDM-style big-room synth.
- Be **mixed at-or-below -22 LUFS** loose target. The composer ducks music
  by -8 dB while voice is speaking and re-attacks at +400ms; with -22 LUFS
  start the bed sits cleanly under a -14 LUFS voiceover.
- Be **at least 30 seconds long** (longer than any video we produce);
  ffmpeg's `aloop=-1` is used so short tracks repeat seamlessly anyway, but
  longer source files have less audible looping.
- Be **CC0 / royalty-free for commercial use**. Document the source +
  license at the bottom of this README on every addition.

## Filename convention

`<slug>.mp3` — slug is descriptive of the mood. Filenames are stable forever
(removing or renaming a file shifts the deterministic-pick assignment for
every existing draft).

## Current library

| Slot | Filename | Mood | Source | License |
|------|----------|------|--------|---------|
| 0 | `warm-acoustic.mp3` | Acoustic guitar, fingerpicked, warm | (TBD — operator commits) | CC0 |
| 1 | `soft-piano.mp3` | Slow piano, sparse, contemplative | (TBD) | CC0 |
| 2 | `ambient-strings.mp3` | Sustained warm strings, no melody | (TBD) | CC0 |
| 3 | `textured-pad.mp3` | Lo-fi warm pad with soft texture | (TBD) | CC0 |

Operator: source these from one of the following CC0 / royalty-free libraries
(in preference order, all are zero-cost):

1. **Pixabay Music** — https://pixabay.com/music/ — filter "instrumental"
2. **Free Music Archive** (CC-BY / CC0 categories only)
3. **YouTube Audio Library** (free for commercial use, monetisable)
4. **Uppbeat** (free tier — needs a per-video credit; we credit "Powered by EIAAW Solutions" so the credit slot is taken; prefer Pixabay/FMA over Uppbeat for that reason)

Drop the chosen .mp3 files in this directory with the filenames above. The
composer detects them at runtime; no config change needed.

## Disabling background music

Set `services.branding.background_music_enabled` to `false` (env var
`BRANDING_BG_MUSIC_ENABLED=false`) to publish video with voiceover only.
Useful for first-run tests.
