# Environment Notes

## 2026-05-11 - Agent eval refuses fake client when API keys are set

- Symptom: `py -m agent_service.eval --baseline agent_service/eval/baseline.json --inject-regression wrong-value` exits before scoring with `RuntimeError: FakeLLMClient instantiated while OPENAI_API_KEY is set in the environment`.
- Likely cause: The eval runner deliberately blocks fake-client tests when `OPENAI_API_KEY` is present, so an accidentally leaked shell variable cannot trigger real API usage.
- Workaround: Remove `OPENAI_API_KEY` and `COHERE_API_KEY` from the current PowerShell process before running the offline eval, for example with `Remove-Item Env:\OPENAI_API_KEY -ErrorAction SilentlyContinue` and `Remove-Item Env:\COHERE_API_KEY -ErrorAction SilentlyContinue`.
- Follow-up needed: None for the code; keep the safety guard. Developers with global API keys should scrub those variables for offline eval shells.
