<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Supported providers

This app talks to any service that implements an API similar to the OpenAI one. Add a service in the
admin settings, set its **Service URL** to the OpenAI-compatible base URL (the part before
`/chat/completions`, `/models`, ...) and enter the API key. The models are then listed from the
service's `/models` endpoint.

Make sure to use the OpenAI-compatible endpoint of a provider instead of the custom ones they provide.

> [!WARNING]
> This app is mainly tested with IONOS, OpenAI, LocalAI and llama.cpp. The services below are known to expose an OpenAI-compatible API, but we do not guarantee that every feature works with every one of them. Most of them only offer some modalities (text, image, transcription, speech). You can connect several services at the same time to cover the rest.

The base URLs below are the ones documented by each provider at the time of writing. Check the
provider's documentation if a URL does not work.

## Self-hosted

| Software | Default service URL | Notes |
|---|---|---|
| [LocalAI](https://localai.io) | `http://localhost:8080/v1` | Detected automatically, image editing uses LocalAI's own endpoint |
| [Ollama](https://docs.ollama.com/api/openai-compatibility) | `http://localhost:11434/v1` | |
| [vLLM](https://docs.vllm.ai/en/stable/serving/online_serving/openai_compatible_server) | `http://localhost:8000/v1` | |
| [SGLang](https://docs.sglang.io/docs/basic_usage/openai_api) | `http://localhost:30000/v1` | |
| [llama.cpp server](https://github.com/ggml-org/llama.cpp/tree/master/tools/server) | `http://localhost:9931/v1` | |
| [LM Studio](https://lmstudio.ai/docs/developer/openai-compat) | `http://localhost:1234/v1` | Proprietary software, free to use but not open source |
| [LiteLLM proxy](https://docs.litellm.ai/docs/simple_proxy) | `http://localhost:4000` | Can put many other providers behind one endpoint |

## Hosted in the EU

| Provider | Service URL | Notes |
|---|---|---|
| [IONOS AI Model Hub](https://docs.ionos.com/cloud/ai/ai-model-hub) | `https://openai.inference.de-txl.ionos.com/v1` | The app has dedicated handling for tool calls and image editing |
| [MistralAI](https://mistral.ai) | `https://api.mistral.ai/v1` | The app has dedicated handling for document attachments |
| [Eden AI](https://www.edenai.co) | `https://api.eu.edenai.run/v3` | Only exposes models cleared for processing in the EU. Use `https://api.edenai.run/v3` for the global endpoint with all models. |
| [Plusserver](https://www.plusserver.com/en/ai-platform/) | See the provider's documentation | |
| [OVHcloud AI Endpoints](https://www.ovhcloud.com/en/public-cloud/ai-endpoints/) | `https://oai.endpoints.kepler.ai.cloud.ovh.net/v1` | |
| [Scaleway Generative APIs](https://www.scaleway.com/en/generative-apis/) | `https://api.scaleway.ai/v1` | |
| [STACKIT AI Model Serving](https://docs.stackit.cloud/products/data-and-ai/ai-model-serving/) | `https://api.openai-compat.model-serving.eu01.onstackit.cloud/v1` | |

## Other hosted services

| Provider | Service URL | Notes |
|---|---|---|
| [OpenAI](https://platform.openai.com) | `https://api.openai.com/v1` | Default, the main tested service |
| [OpenRouter](https://openrouter.ai) | `https://openrouter.ai/api/v1` | The app has dedicated handling for model listing and image editing |
| [Anthropic](https://platform.claude.com/docs/en/cli-sdks-libraries/libraries/openai-sdk) | `https://api.anthropic.com/v1` | Text only. Anthropic describes this compatibility layer as intended for testing |
| [Google Gemini](https://ai.google.dev/gemini-api/docs/openai) | `https://generativelanguage.googleapis.com/v1beta/openai` | |
| [DeepSeek](https://api-docs.deepseek.com) | `https://api.deepseek.com` | Text only |
| [Groq](https://console.groq.com/docs/openai) | `https://api.groq.com/openai/v1` | |
| [Together AI](https://docs.together.ai/docs/inference/openai-compatibility) | `https://api.together.ai/v1` | |
| [Fireworks AI](https://docs.fireworks.ai/tools-sdks/openai-compatibility) | `https://api.fireworks.ai/inference/v1` | |
| [DeepInfra](https://docs.deepinfra.com/chat/overview) | `https://api.deepinfra.com/v1/openai` | |
| [Cerebras](https://inference-docs.cerebras.ai/resources/openai) | `https://api.cerebras.ai/v1` | Text only |
| [Hugging Face Inference Providers](https://huggingface.co/docs/inference-providers) | `https://router.huggingface.co/v1` | Text only |
| [Nebius Token Factory](https://docs.tokenfactory.nebius.com/api-reference/introduction) | `https://api.tokenfactory.nebius.com/v1` | |
| [DemonRoute](https://demonroute.com/integrations) | `https://api.demonroute.com/v1` | Text only, open-weight models |

Missing a provider that works for you? Open a pull request adding it to this file.
