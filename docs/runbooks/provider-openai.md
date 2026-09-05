# Runbook: OpenAI

OpenAI is optional and must remain behind the tenant AI entitlement and usage/quota guards.

OpenAI account ownership, billing ownership, data-processing approval, and model approval are OPS/BUSINESS pending.

## Activation

1. Obtain approval for model, retention, and data-processing scope.
2. Inject `OPENAI_API_KEY` through the secret manager.
3. Set and review `AI_MODEL`, `AI_TIMEOUT`, retry limits, embedding model, and embedding dimensions.
4. Keep embeddings on the `knowledge` queue and confirm the configured vector dimension matches PostgreSQL.

## Verification and rollback

- Confirm no-key chat and embedding operations fail closed without an HTTP request.
- Exercise a sanitized test prompt in the approved environment and inspect quota/usage behavior.
- Disable the AI entitlement or remove the key to roll back; preserve configured fallback behavior.
- Never log API keys, prompts, completions, or embeddings.

CI and E2E use fakes and never call OpenAI.
