# OpenAI integration in Nextcloud

:warning: The smart pickers have been removed from this app
as they are now included in the [Assistant app](https://apps.nextcloud.com/apps/assistant).

This app implements:

* Text generation providers: Free prompt, Summarize, Headline, Context Write, Chat, and Reformulate (using any available large language model)
* A Translation provider (using any available language model)
* A SpeechToText provider (using Whisper)
* An image generation provider

:warning: Context Write, Summarize, Headline and Reformulate have mainly been tested with OpenAI.
They might work when connecting to other services, without any guarantee.

Instead of connecting to the OpenAI API for these, you can also connect to a self-hosted [LocalAI](https://localai.io) instance
or to any service that implements an API similar to the OpenAI one, for example: [Plusserver](https://www.plusserver.com/en/ai-platform/) or [MistralAI](https://mistral.ai).

:warning: This app is mainly tested with OpenAI. We do not guarantee it works perfectly
with other services that implement OpenAI-compatible APIs with slight differences.

## Ethical AI Rating
### Rating for Text generation using ChatGPT via the OpenAI API: 🔴

Negative:
* The software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* The trained model is not freely available, so the model can not be run on-premises
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.


### Rating for Translation using ChatGPT via the OpenAI API: 🔴

Negative:
* The software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* The trained model is not freely available, so the model can not be run on-premises
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.

### Rating for Image generation using DALL·E via the OpenAI API: 🔴

Negative:
* The software for training and inferencing of this model is proprietary, limiting running it locally or training by yourself
* The trained model is not freely available, so the model can not be ran on-premises
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for Speech-To-Text using Whisper via the OpenAI API: 🟡

Positive:
* The software for training and inferencing of this model is open source
* The trained model is freely available, and thus can run on-premise

Negative:
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.

### Rating for Text generation via LocalAI: 🟢

Positive:
* The software for training and inferencing of this model is open source
* The trained model is freely available, and thus can be ran on-premises
* The training data is freely available, making it possible to check or correct for bias or optimise the performance and CO2 usage.


### Rating for Image generation using Stable Diffusion via LocalAI : 🟡

Positive:
* The software for training and inferencing of this model is open source
* The trained model is freely available, and thus can be ran on-premises

Negative:
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for Speech-To-Text using Whisper via LocalAI: 🟡

Positive:
* The software for training and inferencing of this model is open source
* The trained model is freely available, and thus can be ran on-premises

Negative:
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


Learn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/).

## 🔧 Configuration

### Admin settings

There is a "Artificial intelligence" **admin** settings section where you can:
* Choose whether you use OpenAI, a LocalAI instance or another remote service
* Set a global API key (or basic auth credentials) for the Nextcloud instance
* Configure default models and quota settings

### Personal settings

There is a "Artificial intelligence" **personal** settings section to let users set their personal API key or basic auth credentials.
Users can also see their quota information there.
