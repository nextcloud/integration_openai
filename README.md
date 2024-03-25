# OpenAI integration in Nextcloud

:warning: The smart pickers have been removed from this app
as they are now included in the [Assistant app](https://apps.nextcloud.com/apps/assistant).

This app implements:

* Text generation providers: Free prompt, Summarize, Headline and Reformulate (using any available language model)
* A Translation provider (using any available language model)
* A SpeechToText provider (using Whisper)

Instead of connecting to the OpenAI API for these, you can also connect to a self-hosted [LocalAI](https://localai.io) instance.

## Ethical AI Rating
### Rating for Text generation using ChatGPT via OpenAI API: 🔴

Negative:
* the software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* the trained model is not freely available, so the model can not be run on-premises
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.


### Rating for Translation using ChatGPT via OpenAI API: 🔴

Negative:
* the software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* the trained model is not freely available, so the model can not be run on-premises
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.

### Rating for Image generation using DALL·E via OpenAI API: 🔴

Negative:
* the software for training and inferencing of this model is proprietary, limiting running it locally or training by yourself
* the trained model is not freely available, so the model can not be ran on-premises
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for Speech-To-Text using Whisper via OpenAI API: 🟡

Positive:
* the software for training and inferencing of this model is open source
* The trained model is freely available, and thus can run on-premise

Negative:
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.

### Rating for Text generation via LocalAI: 🟢

Positive:
* the software for training and inferencing of this model is open source
* the trained model is freely available, and thus can be ran on-premises
* the training data is freely available, making it possible to check or correct for bias or optimise the performance and CO2 usage.


### Rating for Image generation using Stable Diffusion via LocalAI : 🟡

Positive:
* the software for training and inferencing of this model is open source
* the trained model is freely available, and thus can be ran on-premises

Negative:
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for Speech-To-Text using Whisper via LocalAI: 🟡

Positive:
* the software for training and inferencing of this model is open source
* the trained model is freely available, and thus can be ran on-premises

Negative:
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


Learn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/).

## 🔧 Configuration

### Admin settings

There is a "Connected accounts" **admin** settings section to set a global OpenAI API key or LocalAI credentials for the Nextcloud instance.
It is also possible to configure default models and quota settings.

### Personal settings

There is a "Connected accounts" **personal** settings section to let users set their personal OpenAI API key or LocalAI credentials.
Users can also see their quota information there.
