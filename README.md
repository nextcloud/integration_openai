<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# IBM watsonx AI integration in Nextcloud

<!-- [![REUSE status](https://api.reuse.software/badge/github.com/nextcloud/integration_watsonx)](https://api.reuse.software/info/github.com/nextcloud/integration_watsonx) -->

This app implements the following text generation providers
using any freely-available large language model:
Free prompt, Summarize, Headline, Context Write, Chat, and Reformulate.

You can connect to the watsonx.ai service provided by IBM
or to a self-hosted cluster running IBM Software Hub and related services.

## Improve AI task pickup speed

To avoid task processing execution delay,
setup at 4 background job workers in the main server (where Nextcloud is installed).
The setup process is documented here:
https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html#improve-ai-task-pickup-speed

## Ethical AI Rating

### Rating for Text generation via IBM watsonx.ai: 🟠

Positive:
* The provided foundation models are freely available, and thus can be ran on-premises

Negative:
* The software for training and inference of models is proprietary, limiting modifications to the API or other functionality
* Some foundation models are trained on data that is not freely available, limiting the ability to fine tune them

Learn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/).

## Limitations

> [!WARNING]
> This app is still in early development
> and has only been tested with IBM watsonx.ai as a Service.
> The following list details some missing features that may be added in a future release.

* Support for agency features (i.e. IBM watsonx as chat with tools provider)
* Support for additional models without complete API functionality
  (see: https://www.ibm.com/watsonx/developer/get-started/models/)
* Support for IBM Cloud Pak for Data Platform API
  (for identity management on self-hosted instances)
* Support for more than 100 models deployed in IBM watsonx.ai
* Ability to select an IBM Cloud location from a dropdown list
  (as a workaround, enter the location's API URL manually)

## 🔧 Configuration

### Admin settings

There is a "Artificial intelligence" **admin** settings section where you can:
* Choose whether you use an IBM-hosted watsonx.ai instance or another remote service
* Set a global API key and cloud resource for the Nextcloud instance
* Configure default models and quota settings

### Personal settings

There is a "Artificial intelligence" **personal** settings section to let users set their personal API key and cloud resources.
Users can also see their quota information there.
