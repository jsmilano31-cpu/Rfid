# Campus Assistant

The center navigation button opens one local AI conversation. There is no keyword
responder or non-AI chat fallback. Input, sending, and reference-source controls
remain disabled until a real model load and generation check succeeds.

## Availability

Qwen3 0.6B Q4_0 is an optional verified 429 MB download. The pinned
`dev.ffmpegkit-maintained:llama-android:0.1.1` runtime includes ARM64 only.
The installed emulator reports native x86_64 plus ARM translation. Translation
is no longer accepted as native compatibility; this build shows an unavailable
state on that emulator. Use a native ARM64 Android phone for inference.
This change does not add x86_64 inference support or cloud inference.

A stored file does not mean AI is ready: opening the screen checks generation.
Download and retry both initialize the model. Load/generation errors disable
chat until retry succeeds. Network errors are shown separately; they never
substitute canned record answers. The Stop action suppresses a cancelled reply,
but the native call finishes before model memory is released.

## References for AI answers

- None: offline general conversation after model setup.
- School records: fetch the existing authenticated student/portal.php or
  teacher/portal.php under https://jsmkj.space/rfid-attendance-web/public/ and
  attach minimized record context to the local model. No new API or SQL access.
  Account identifiers, profile details, and messages are omitted. Teacher data
  does not include personal grades or student rosters. Record answers are now
  model-generated, so factual correctness requires physical-device evaluation.
- Web search: send only the current typed question to Wikipedia's public search
  endpoint, then give the results to the local model. Responses display source
  links. History and school data are not sent to the search provider. This is
  Wikipedia search, not a general web crawler. Avoid private information in the
  question when selecting this source.

Select a source for subsequent questions too when fresh evidence is needed.
The model receives the last two conversational turns (bounded) and up to 3,500
characters of evidence. Large school datasets are marked as partial/truncated;
not all records fit. Source data is treated as untrusted reference material in
instructions. The small model may still misunderstand or hallucinate; compare
important answers with the dedicated records screens.

## Storage and runtime

Model: https://huggingface.co/ggml-org/Qwen3-0.6B-GGUF
Runtime: https://github.com/ffmpegkit-maintained/llama-android (MIT)
Model license: https://huggingface.co/Qwen/Qwen3-0.6B (Apache 2.0)

Weights are checked against the exact size and SHA-256 and stored in private
non-backed-up storage. Failed/cancelled partial downloads are deleted. Remove
clears the model and conversation. Keep the screen open during download.
History is only in screen memory and clears on close/sign-out/role changes.
Inference uses two CPU threads, a 4,096-token context, and 320 output tokens.
Native work is serialized and model memory is released after every call.

## Validation

Build and tests:

```powershell
.\gradlew.bat :app:assembleDebug :app:testDebugUnitTest
```

Unit tests cover native ABI detection, minimized record context, teacher role
boundaries, and explicit truncation. Physical ARM64 model initialization,
inference quality, latency, and memory use still require device testing.

September 7 validation: debug APK built; all 12 JVM tests passed. The connected
Android 17 test passed using platform accessibility instead of Espresso. It
verified the unavailable message, disabled send button and composer, and absence
of record/web fallbacks on the x86 emulator.
