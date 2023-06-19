# OpenAI integration in Nextcloud

This app includes 3 custom smart pickers for Nextcloud:
* ChatGPT answers
* DALL·E 2 images
* Whisper dictation

It also implements

* a Translation provider (using ChatGPT)
* a SpeechToText provider (using Whisper)

Instead of connecting to the OpenAI API for these, you can also connect to a self-hosted LocalAI instance.

## Ethical AI Rating
### Rating for ChatGPT via OpenAI API: 🔴

Negative:
* the software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* the trained model is not freely available, so the model can not be run on-premises
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for DALL·E via OpenAI API: 🔴

Negative:
* the software for training and inferencing of this model is proprietary, limiting running it locally or training by yourself
* the trained model is not freely available, so the model can not be ran on-premises
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


### Rating for Whisper via OpenAI API: 🟡

Positive:
* the software for training and inferencing of this model is open source
* the trained model is freely available, and thus can be ran on-premises

Negative:
* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.


Learn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/).

## 🔧 Configuration

### Admin settings

There is a "Connected accounts" **admin** settings section to set your OpenAI API key.
