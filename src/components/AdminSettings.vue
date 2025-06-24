<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI and LocalAI integration') }}
		</h2>
		<div id="openai-content">
			<div>
				<NcNoteCard v-if="!state.assistant_enabled" type="warning">
					{{ t('integration_openai', 'The Assistant app is not enabled. You need it to use the features provided by the OpenAI/LocalAI integration app.') }}
					<a class="external" :href="appSettingsAssistantUrl" target="_blank">
						{{ t('integration_openai', 'Assistant app') }}
					</a>
				</NcNoteCard>
				<NcNoteCard type="info">
					{{ t('integration_openai', 'Services with an OpenAI-compatible API:') }}
					<div class="services">
						<a class="external" href="https://platform.openai.com/api-keys" target="_blank">OpenAI</a>
						<a class="external" href="https://docs.ionos.com/cloud/ai/ai-model-hub" target="_blank">IONOS AI Model Hub</a>
						<a class="external" href="https://console.groq.com" target="_blank">Groqcloud</a>
						<a class="external" href="https://localai.io/" target="_blank">LocalAI</a>
						<a class="external" href="https://ollama.com/" target="_blank">Ollama</a>
						<a class="external" href="https://mistral.ai" target="_blank">MistralAI</a>
						<a class="external" href="https://www.plusserver.com/en/ai-platform/" target="_blank">Plusserver</a>
					</div>
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="openai-url"
						v-model="state.url"
						class="input"
						:label="t('integration_openai', 'Service URL')"
						:placeholder="t('integration_openai', 'Example: {example}', { example: 'http://localhost:8080/v1' })"
						:show-trailing-button="!!state.url"
						@update:model-value="onSensitiveInput(true)"
						@trailing-button-click="state.url = '' ; onSensitiveInput(true)">
						<EarthIcon />
					</NcTextField>
					<NcButton variant="tertiary"
						:title="t('integration_openai', 'Leave empty to use {openaiApiUrl}', { openaiApiUrl: 'https://api.openai.com/v1' })">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcNoteCard type="info">
					{{ t('integration_openai', 'With the current configuration, the target URL used to get the models is:') }}
					<br>
					<strong>{{ modelEndpointUrl }}</strong>
				</NcNoteCard>
				<NcNoteCard type="info">
					{{ t('integration_openai', 'This should include the address of your LocalAI instance (or any service implementing an API similar to OpenAI) along with the root path of the API. This URL will be accessed by your Nextcloud server.') }}
					<br>
					{{ t('integration_openai', 'This can be a local address with a port like {example}. In this case, make sure \'allow_local_remote_servers\' is set to true in config.php.', { example: 'http://localhost:8080/v1' }) }}
				</NcNoteCard>
				<div v-if="state.url !== ''" class="line">
					<NcTextField
						id="openai-service-name"
						v-model="state.service_name"
						class="input"
						:label="t('integration_openai', 'Service name (optional)')"
						:placeholder="t('integration_openai', 'Example: LocalAI of university ABC')"
						:show-trailing-button="!!state.service_name"
						@update:model-value="onInput()"
						@trailing-button-click="state.service_name = '' ; onInput()" />
					<NcButton variant="tertiary"
						:title="t('integration_openai', 'This name will be displayed as provider name in the AI admin settings')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<div class="line">
					<NcInputField
						id="openai-api-timeout"
						v-model="state.request_timeout"
						class="input"
						type="number"
						:label="t('integration_openai', 'Request timeout (seconds)')"
						:placeholder="t('integration_openai', 'Example: {example}', { example: '240' })"
						:show-trailing-button="!!state.request_timeout"
						@update:model-value="onInput()"
						@trailing-button-click="state.request_timeout = '' ; onInput()">
						<TimerAlertOutlineIcon />
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton variant="tertiary"
						:title="t('integration_openai', 'Timeout for the request to the external API')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Authentication') }}
				</h2>
				<div v-show="state.url !== ''" class="line column">
					<label>
						{{ t('integration_openai', 'Authentication method') }}
					</label>
					<div class="radios">
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:model-value="!state.use_basic_auth"
							type="radio"
							button-variant-grouped="horizontal"
							name="auth_method"
							@update:model-value="onCheckboxChanged(false, 'use_basic_auth')">
							{{ t('assistant', 'API key') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:model-value="state.use_basic_auth"
							type="radio"
							button-variant-grouped="horizontal"
							name="auth_method"
							@update:model-value="onCheckboxChanged(true, 'use_basic_auth')">
							{{ t('assistant', 'Basic Authentication') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<div v-show="state.url === '' || !state.use_basic_auth" class="line">
					<NcTextField
						id="openai-api-key"
						v-model="state.api_key"
						class="input"
						type="password"
						:readonly="readonly"
						:label="t('integration_openai', 'API key (mandatory with OpenAI)')"
						:show-trailing-button="!!state.api_key"
						@update:model-value="onSensitiveInput(true)"
						@trailing-button-click="state.api_key = '' ; onSensitiveInput(true)"
						@focus="readonly = false">
						<KeyIcon />
					</NcTextField>
				</div>
				<NcNoteCard v-show="state.url === ''" type="info">
					{{ t('integration_openai', 'You can create an API key in your OpenAI account settings') }}:
					&nbsp;
					<a :href="apiKeyUrl" target="_blank" class="external">
						{{ apiKeyUrl }}
					</a>
				</NcNoteCard>
				<div v-show="state.url !== '' && state.use_basic_auth">
					<div class="line">
						<NcTextField
							id="openai-basic-user"
							v-model="state.basic_user"
							class="input"
							:readonly="readonly"
							:label="t('integration_openai', 'Basic Auth user')"
							:show-trailing-button="!!state.basic_user"
							@update:model-value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_user = '' ; onSensitiveInput(true)"
							@focus="readonly = false">
							<AccountIcon />
						</NcTextField>
					</div>
					<div class="line">
						<NcTextField
							id="openai-basic-password"
							v-model="state.basic_password"
							class="input"
							type="password"
							:readonly="readonly"
							:label="t('integration_openai', 'Basic Auth password')"
							:show-trailing-button="!!state.basic_password"
							@update:model-value="onSensitiveInput(true)"
							@trailing-button-click="state.basic_password = '' ; onSensitiveInput(true)"
							@focus="readonly = false">
							<KeyIcon />
						</NcTextField>
					</div>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Text generation') }}
				</h2>
				<div v-if="state.url !== ''" class="line column">
					<label>
						<EarthIcon :size="20" class="icon" />
						{{ t('integration_openai', 'Text completion endpoint') }}
					</label>
					<div class="radios">
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:model-value="state.chat_endpoint_enabled"
							type="radio"
							button-variant-grouped="horizontal"
							name="chat_endpoint"
							@update:model-value="onCheckboxChanged(true, 'chat_endpoint_enabled', false)">
							{{ t('assistant', 'Chat completions') }}
						</NcCheckboxRadioSwitch>
						<NcCheckboxRadioSwitch
							:button-variant="true"
							:model-value="!state.chat_endpoint_enabled"
							type="radio"
							button-variant-grouped="horizontal"
							name="chat_endpoint"
							@update:model-value="onCheckboxChanged(false, 'chat_endpoint_enabled', false)">
							{{ t('assistant', 'Completions') }}
						</NcCheckboxRadioSwitch>
					</div>
				</div>
				<NcNoteCard type="info">
					{{ state.url === ''
						? t('integration_openai', 'Selection of chat/completion endpoint is not available for OpenAI since it implicitly uses chat completions for "instruction following" fine-tuned models.')
						: t('integration_openai', 'Using the chat endpoint may improve text generation quality for "instruction following" fine-tuned models.') }}
				</NcNoteCard>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.text"
						class="model-select"
						:clearable="state.default_completion_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default completion model to use')"
						:no-wrap="true"
						input-id="openai-model-select"
						@update:model-value="onModelSelected('text', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_openai', 'More information about OpenAI models')"
						href="https://beta.openai.com/docs/models"
						target="_blank">
						<NcButton variant="tertiary" aria-label="openai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_openai', 'More information about LocalAI models')"
						href="https://localai.io/model-compatibility/index.html"
						target="_blank">
						<NcButton variant="tertiary" aria-label="localai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
				</div>
				<div class="line">
					<NcTextField
						id="llm-extra-params"
						v-model="state.llm_extra_params"
						class="input"
						:label="t('integration_openai', 'Extra completion model parameters')"
						:show-trailing-button="!!state.llm_extra_params"
						@update:model-value="onInput()"
						@trailing-button-click="state.llm_extra_params = '' ; onInput()" />
					<NcButton variant="tertiary"
						:title="llmExtraParamHint">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<div class="line">
					<!--Input for max chunk size (prompt length) for a single request-->
					<NcInputField
						id="openai-chunk-size"
						v-model="state.chunk_size"
						class="input"
						type="number"
						:label="t('integration_openai', 'Max input tokens per request')"
						:show-trailing-button="!!state.chunk_size"
						@update:model-value="onInput()"
						@trailing-button-click="state.chunk_size = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton variant="tertiary"
						:title="t('integration_openai', 'Split the prompt into chunks with each chunk being no more than the specified number of tokens (0 disables chunking)')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Image generation') }}
				</h2>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.image"
						class="model-select"
						:clearable="state.default_image_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default image generation model to use')"
						:no-wrap="true"
						input-id="openai-model-select"
						@update:model-value="onModelSelected('image', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_openai', 'More information about OpenAI models')"
						href="https://beta.openai.com/docs/models"
						target="_blank">
						<NcButton variant="tertiary" aria-label="openai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_openai', 'More information about LocalAI models')"
						href="https://localai.io/model-compatibility/index.html"
						target="_blank">
						<NcButton variant="tertiary" aria-label="localai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
				</div>
				<NcNoteCard v-else type="info">
					{{ t('integration_openai', 'No models to list') }}
				</NcNoteCard>
				<div class="line">
					<NcTextField
						id="default-image-size"
						v-model="state.default_image_size"
						class="input"
						:label="t('integration_openai', 'Default image size')"
						:show-trailing-button="!!state.default_image_size"
						@update:model-value="onInput()"
						@trailing-button-click="state.default_image_size = '' ; onInput()" />
					<NcButton variant="tertiary"
						:title="defaultImageSizeParamHint">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcCheckboxRadioSwitch
					:model-value="state.image_request_auth"
					@update:model-value="onCheckboxChanged($event, 'image_request_auth', false)">
					{{ t('integration_openai', 'Use authentication for image retrieval request') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Audio transcription') }}
				</h2>
				<div v-if="models"
					class="line line-select">
					<NcSelect
						v-model="selectedModel.stt"
						class="model-select"
						:clearable="state.default_image_model_id !== DEFAULT_MODEL_ITEM.id"
						:options="formattedModels"
						:input-label="t('integration_openai', 'Default transcription model to use')"
						:no-wrap="true"
						input-id="openai-stt-model-select"
						@update:model-value="onModelSelected('stt', $event)" />
					<a v-if="state.url === ''"
						:title="t('integration_openai', 'More information about OpenAI models')"
						href="https://beta.openai.com/docs/models"
						target="_blank">
						<NcButton variant="tertiary" aria-label="openai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
					<a v-else
						:title="t('integration_openai', 'More information about LocalAI models')"
						href="https://localai.io/model-compatibility/index.html"
						target="_blank">
						<NcButton variant="tertiary" aria-label="localai-info">
							<template #icon>
								<HelpCircleIcon />
							</template>
						</NcButton>
					</a>
				</div>
				<NcNoteCard v-else type="info">
					{{ t('integration_openai', 'No models to list') }}
				</NcNoteCard>
			</div>
			<h2>
				{{ t('integration_openai', 'Text to speech') }}
			</h2>
			<div v-if="models"
				class="line line-select">
				<NcSelect
					v-model="selectedModel.tts"
					class="model-select"
					:clearable="state.default_tts_model_id !== DEFAULT_MODEL_ITEM.id"
					:options="formattedModels"
					:input-label="t('integration_openai', 'Default speech generation model to use')"
					:no-wrap="true"
					input-id="openai-tts-model-select"
					@input="onModelSelected('tts', $event)" />
				<a v-if="state.url === ''"
					:title="t('integration_openai', 'More information about OpenAI models')"
					href="https://beta.openai.com/docs/models"
					target="_blank">
					<NcButton variant="tertiary" aria-label="openai-info">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
				<a v-else
					:title="t('integration_openai', 'More information about LocalAI models')"
					href="https://localai.io/model-compatibility/index.html"
					target="_blank">
					<NcButton variant="tertiary" aria-label="localai-info">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
			</div>
			<NcNoteCard v-else type="info">
				{{ t('integration_openai', 'No models to list') }}
			</NcNoteCard>
			<div class="line column">
				<label>{{ t('integration_openai', 'TTS Voices') }}
					<NcButton
						:title="t('integration_openai', 'A list of voices supported by the endpoint you are using. Defaults to openai\'s list.')"
						variant="tertiary"
						aria-label="voices-info">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</label>
				<NcSelect v-model="state.tts_voices"
					:label-outside="true"
					multiple
					taggable
					style="width: 350px;"
					@input="onInput()" />
			</div>
			<NcSelect
				:model-value="state.default_tts_voice"
				class="model-select"
				:options="state.tts_voices"
				:input-label="t('integration_openai', 'Default voice to use')"
				:no-wrap="true"
				input-id="openai-tts-voices-select"
				@click="onInput()" />
			<div>
				<h2>
					{{ t('integration_openai', 'Usage limits') }}
				</h2>
				<div class="line">
					<!--Time period in days for the token usage-->
					<NcInputField
						id="openai-api-quota-period"
						v-model="state.quota_period"
						class="input"
						type="number"
						:label="t('integration_openai', 'Quota enforcement time period (days)')"
						:show-trailing-button="!!state.quota_period"
						@update:model-value="onInput()"
						@trailing-button-click="state.quota_period = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
				</div>
				<h4>
					{{ t('integration_openai', 'Usage quotas per time period') }}
				</h4>
				<!--Loop through all quota types and list an input for them on this line-->
				<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
				<table class="quota-table">
					<thead>
						<tr>
							<th width="120px">
								{{ t('integration_openai', 'Quota type') }}
							</th>
							<th>{{ t('integration_openai', 'Per-user quota / period') }}</th>
							<th v-if="quotaInfo !== null">
								{{ t('integration_openai', 'Current system-wide usage / period') }}
							</th>
						</tr>
					</thead>
					<tbody v-if="quotaInfo !== null">
						<tr v-for="(_,index) in state.quotas" :key="index">
							<td class="text-cell">
								{{ quotaInfo[index].type }}
							</td>
							<td>
								<input :id="'openai-api-quota-' + index"
									v-model.number="state.quotas[index]"
									:title="t('integration_openai', 'A per-user limit for usage of this API type (0 for unlimited)')"
									type="number"
									@input="onInput()">
								<span v-if="quotaInfo !== null" class="text-cell">
									{{ quotaInfo[index].unit }}
								</span>
							</td>
							<td v-if="quotaInfo !== null" class="text-cell">
								{{ quotaInfo[index].used }}
							</td>
						</tr>
					</tbody>
				</table>
				<div class="line">
					<!--Input for max number of tokens to generate for a single request-->
					<!--Only enforced if the user has not provided an own API key (in the case of OpenAI)-->
					<NcInputField
						id="openai-api-max-tokens"
						v-model="state.max_tokens"
						class="input"
						type="number"
						:label="t('integration_openai', 'Max new tokens per request')"
						:show-trailing-button="!!state.max_tokens"
						@update:model-value="onInput()"
						@trailing-button-click="state.max_tokens = '' ; onInput()">
						<template #trailing-button-icon>
							<CloseIcon :size="20" />
						</template>
					</NcInputField>
					<NcButton variant="tertiary"
						:title="t('integration_openai', 'Maximum number of new tokens generated for a single text generation prompt')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</div>
				<NcCheckboxRadioSwitch
					:model-value="state.use_max_completion_tokens_param"
					@update:model-value="onCheckboxChanged($event, 'use_max_completion_tokens_param', false)">
					{{ t('integration_openai', 'Use "{newParam}" parameter instead of the deprecated "{deprecatedParam}"', { newParam: 'max_completion_tokens', deprecatedParam: 'max_tokens' }) }}
				</NcCheckboxRadioSwitch>
			</div>
			<div>
				<h2>
					{{ t('integration_openai', 'Select enabled features') }}
				</h2>
				<NcCheckboxRadioSwitch
					:model-value="state.translation_provider_enabled"
					@update:model-value="onCheckboxChanged($event, 'translation_provider_enabled', false)">
					{{ t('integration_openai', 'Translation provider (to translate Talk messages for example)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="state.llm_provider_enabled"
					@update:model-value="onCheckboxChanged($event, 'llm_provider_enabled', false)">
					{{ t('integration_openai', 'Text processing providers (to generate text, summarize, context write, etc.)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="state.t2i_provider_enabled"
					@update:model-value="onCheckboxChanged($event, 't2i_provider_enabled', false)">
					{{ t('integration_openai', 'Image generation provider') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="state.stt_provider_enabled"
					@update:model-value="onCheckboxChanged($event, 'stt_provider_enabled', false)">
					{{ t('integration_openai', 'Speech-to-text provider (to transcribe Talk recordings for example)') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:model-value="state.tts_provider_enabled"
					@update:model-value="onCheckboxChanged($event, 'tts_provider_enabled', false)">
					{{ t('integration_openai', 'Text-to-speech provider') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>
	</div>
</template>

<script>
import AccountIcon from 'vue-material-design-icons/Account.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import TimerAlertOutlineIcon from 'vue-material-design-icons/TimerAlertOutline.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'

import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { confirmPassword } from '@nextcloud/password-confirmation'
import { generateUrl } from '@nextcloud/router'
import debounce from 'debounce'

const DEFAULT_MODEL_ITEM = { id: 'Default' }

export default {
	name: 'AdminSettings',

	components: {
		OpenAiIcon,
		KeyIcon,
		CloseIcon,
		AccountIcon,
		EarthIcon,
		TimerAlertOutlineIcon,
		HelpCircleIcon,
		NcButton,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcTextField,
		NcInputField,
		NcNoteCard,
	},

	data() {
		return {
			state: loadState('integration_openai', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			models: null,
			selectedModel: {
				text: null,
				image: null,
				stt: null,
				tts: null,
			},
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
			quotaInfo: null,
			llmExtraParamHint: t('integration_openai', 'JSON object. Check the API documentation to get the list of all available parameters. For example: {example}', { example: '{"stop":".","temperature":0.7}' }, null, { escape: false, sanitize: false }),
			defaultImageSizeParamHint: t('integration_openai', 'Must be in 256x256 format (default is {default})', { default: '1024x1024' }),
			DEFAULT_MODEL_ITEM,
			appSettingsAssistantUrl: generateUrl('/settings/apps/integration/assistant'),
		}
	},

	computed: {
		modelEndpointUrl() {
			if (this.state.url === '') {
				return 'https://api.openai.com/v1/models'
			}
			return this.state.url.replace(/\/*$/, '/models')
		},
		isUsingOpenAI() {
			return this.state.url === ''
		},
		configured() {
			return !!this.state.url || !!this.state.api_key || !!this.state.basic_user || !!this.state.basic_password
		},
		formattedModels() {
			if (this.models) {
				return this.models.map(m => {
					return {
						id: m.id,
						value: m.id,
						label: m.id
							+ (m.owned_by ? ' (' + m.owned_by + ')' : ''),
					}
				})
			}
			return []
		},
	},

	mounted() {
		if (this.configured) {
			this.getModels(false)
		}
		this.loadQuotaInfo()
	},

	methods: {
		modelToNcSelectObject(model) {
			return {
				id: model.id,
				value: model.id,
				label: model.id + (model.owned_by ? ' (' + model.owned_by + ')' : ''),
			}
		},

		getModels(shouldSave = true) {
			this.models = null
			if (!this.configured) {
				return
			}
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data ?? []
					if (this.isUsingOpenAI) {
						this.models.unshift(DEFAULT_MODEL_ITEM)
					}
					const defaultCompletionModelId = this.state.default_completion_model_id || response.data?.default_completion_model_id
					const completionModelToSelect = this.models.find(m => m.id === defaultCompletionModelId)
						|| this.models.find(m => m.id === 'gpt-3.5-turbo')
						|| this.models[1]
						|| this.models[0]

					const defaultImageModelId = this.state.default_image_model_id || response.data?.default_image_model_id
					const imageModelToSelect = this.models.find(m => m.id === defaultImageModelId)
						|| this.models.find(m => m.id === 'dall-e-2')
						|| this.models[1]
						|| this.models[0]

					const defaultSttModelId = this.state.default_stt_model_id || response.data?.default_stt_model_id
					const sttModelToSelect = this.models.find(m => m.id === defaultSttModelId)
						|| this.models.find(m => m.id.match(/whisper/i))
						|| this.models[1]
						|| this.models[0]

					const defaultTtsModelId = this.state.default_tts_model_id || response.data?.default_tts_model_id
					const ttsModelToSelect = this.models.find(m => m.id === defaultTtsModelId)
						|| this.models.find(m => m.id.match(/tts/i))
						|| this.models[1]
						|| this.models[0]

					this.selectedModel.text = this.modelToNcSelectObject(completionModelToSelect)
					this.selectedModel.image = this.modelToNcSelectObject(imageModelToSelect)
					this.selectedModel.stt = this.modelToNcSelectObject(sttModelToSelect)
					this.selectedModel.tts = this.modelToNcSelectObject(ttsModelToSelect)

					// save if url/credentials were changed OR if the values are not up-to-date in the stored settings
					if (shouldSave
						|| this.state.default_completion_model_id !== this.selectedModel.text.id
						|| this.state.default_image_model_id !== this.selectedModel.image.id) {
						this.saveOptions({
							default_completion_model_id: this.selectedModel.text.id,
							default_image_model_id: this.selectedModel.image.id,
						}, false)
					}

					this.state.default_completion_model_id = completionModelToSelect.id
					this.state.default_image_model_id = imageModelToSelect.id
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to load models')
						+ ': ' + this.reduceStars(error.response?.data?.error),
						{ timeout: 10000 },
					)
					console.error(error)
				})
		},
		onModelSelected(type, selected) {
			console.debug(`Selected model: ${type}: ${selected}`)
			if (selected == null) {
				if (type === 'image') {
					this.selectedModel.image = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_image_model_id = DEFAULT_MODEL_ITEM.id
				} else if (type === 'text') {
					this.selectedModel.text = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_completion_model_id = DEFAULT_MODEL_ITEM.id
				} else if (type === 'stt') {
					this.selectedModel.stt = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_stt_model_id = DEFAULT_MODEL_ITEM.id
				} else if (type === 'tts') {
					this.selectedModel.tts = this.modelToNcSelectObject(DEFAULT_MODEL_ITEM)
					this.state.default_tts_model_id = DEFAULT_MODEL_ITEM.id
				}
			} else {
				if (type === 'image') {
					this.state.default_image_model_id = selected.id
				} else if (type === 'text') {
					this.state.default_completion_model_id = selected.id
				} else if (type === 'stt') {
					this.state.default_stt_model_id = selected.id
				} else if (type === 'tts') {
					this.state.default_tts_model_id = selected.id
				}
			}
			this.saveOptions({
				default_completion_model_id: this.state.default_completion_model_id,
				default_image_model_id: this.state.default_image_model_id,
				default_stt_model_id: this.state.default_stt_model_id,
				default_tts_model_id: this.state.default_tts_model_id,
			})
		},
		loadQuotaInfo() {
			const url = generateUrl('/apps/integration_openai/admin-quota-info')
			return axios.get(url)
				.then((response) => {
					this.quotaInfo = response.data
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to load quota info')
						+ ': ' + this.reduceStars(error.response?.data?.error),
						{ timeout: 10000 },
					)
				})
		},
		capitalizedWord(word) {
			return word.charAt(0).toUpperCase() + word.slice(1)
		},
		async onCheckboxChanged(newValue, key, getModels = true, sensitive = false) {
			this.state[key] = newValue
			await this.saveOptions({ [key]: this.state[key] }, sensitive)
			if (getModels) {
				this.getModels()
			}
		},
		onSensitiveInput: debounce(async function(getModels = true) {
			const values = {
				basic_user: (this.state.basic_user ?? '').trim(),
				url: (this.state.url ?? '').trim(),
			}
			if (this.state.api_key !== 'dummyApiKey') {
				values.api_key = (this.state.api_key ?? '').trim()
			}
			if (this.state.basic_password !== 'dummyPassword') {
				values.basic_password = (this.state.basic_password ?? '').trim()
			}
			await this.saveOptions(values, true)
			if (getModels) {
				this.getModels()
			}
		}, 2000),
		onInput: debounce(async function() {
			const values = {
				service_name: this.state.service_name,
				request_timeout: parseInt(this.state.request_timeout),
				chunk_size: parseInt(this.state.chunk_size),
				max_tokens: parseInt(this.state.max_tokens),
				llm_extra_params: this.state.llm_extra_params,
				default_image_size: this.state.default_image_size,
				quota_period: parseInt(this.state.quota_period),
				quotas: this.state.quotas,
				tts_voices: this.state.tts_voices,
				default_tts_voice: this.state.default_tts_voice,
			}
			await this.saveOptions(values, false)
		}, 2000),
		async saveOptions(values, sensitive = false, notify = true) {
			if (sensitive) {
				await confirmPassword()
			}

			const req = {
				values,
			}
			const url = sensitive ? generateUrl('/apps/integration_openai/admin-config/sensitive') : generateUrl('/apps/integration_openai/admin-config')
			try {
				await axios.put(url, req)
				if (notify) {
					showSuccess(t('integration_openai', 'OpenAI admin options saved'))
				}
			} catch (error) {
				console.error(error)
				showError(
					t('integration_openai', 'Failed to save OpenAI admin options')
					+ ': ' + this.reduceStars(error.response?.data?.error),
					{ timeout: 10000 },
				)
			}
		},
		reduceStars(text) {
			if (!text) {
				return '(none)'
			}
			return text.replace(/[*]{4,}/g, '***')
		},
	},
}
</script>

<style scoped lang="scss">
#openai_prefs {
	#openai-content {
		margin-left: 40px;
	}

	h2 {
		justify-content: start;
		display: flex;
		align-items: center;
		gap: 8px;
		margin-top: 8px;
	}

	.services {
		display: flex;
		gap: 4px;
	}

	.radios {
		display: flex;
	}

	.quota-table {
		padding: 4px 8px 4px 8px;
		border: 2px solid var(--color-border);
		border-radius: var(--border-radius);
		.text-cell {
			opacity: 0.5;
		}
		th, td {
			width: 300px;
			text-align: left;
			> input:invalid {
				border-color: var(--color-error);
			}
		}
	}

	.line {
		display: flex;
		align-items: center;
		margin-top: 12px;
		.icon {
			margin-right: 4px;
		}
		&.line-select {
			align-items: end;
		}
		> label {
			width: 350px;
			display: flex;
			align-items: center;
		}
		> input, .input {
			width: 350px;
			margin-top: 0;
		}
		> input:invalid {
			border-color: var(--color-error);
		}
		> input[type='radio'] {
			width: auto;
		}

		&.column {
			flex-direction: column;
			align-items: start;
			gap: 8px;
		}
	}

	.model-select {
		min-width: 350px;
		margin: 0 !important;
	}
}
</style>
