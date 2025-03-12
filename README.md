<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# IBM watsonx AI integration in Nextcloud

<!-- [![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/integration_watsonx)](https://api.reuse.software/info/github.com/nextcloud/integration_watsonx) -->

This app implements the following text generation providers
using any freely-available large language model:
Free prompt, Summarize, Headline, Context Write, Chat, and Reformulate.

:warning: This app is mainly tested with IBM watsonx.ai as a Service.
We do not guarantee it works perfectly with other services
that implement watsonx.ai-compatible APIs with slight differences.

Instead of connecting to the serviced watsonx.ai, you can also connect to a self-hosted instance
or to any service that implements an API similar to watsonx.ai.

## Improve AI task pickup speed

To avoid task processing execution delay, setup at 4 background job workers in the main server (where Nextcloud is installed). The setup process is documented here: https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html#improve-ai-task-pickup-speed

## Ethical AI Rating

<!-- TODO: update the AI ratings above and in info.xml -->

### Rating for Text generation via IBM watsonx.ai as a Service: 🔴

Negative:
* The software for training and inference of this model is proprietary, limiting running it locally or training by yourself
* The trained model is not freely available, so the model can not be run on-premises
* The training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model's performance and CO2 usage.


### Rating for Text generation via self-hosted IBM watsonx.ai: 🟢

Positive:
* The software for training and inferencing of this model is open source
* The trained model is freely available, and thus can be ran on-premises
* The training data is freely available, making it possible to check or correct for bias or optimise the performance and CO2 usage.

Learn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/).

## 🔧 Configuration

### Admin settings

There is a "Artificial intelligence" **admin** settings section where you can:
* Choose whether you use an IBM-hosted watsonx.ai instance or another remote service
* Set a global API key (or basic auth credentials) for the Nextcloud instance
* Configure default models and quota settings

### Personal settings

There is a "Artificial intelligence" **personal** settings section to let users set their personal API key or basic auth credentials.
Users can also see their quota information there.
