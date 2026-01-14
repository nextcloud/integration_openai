<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div>
		<NcNoteCard v-if="localState.url!==''" type="info">
			{{ t('integration_openai', 'Service url overridden for {task} to {url}', { task:aiTask, url: localState.url }) }}
		</NcNoteCard>
		<div class="line">
			<NcButton
				@click="isExpanded = !isExpanded">
				<template #icon>
					<UnfoldLessHorizontal v-if="isExpanded" :size="20" />
					<UnfoldMoreHorizontal v-else :size="20" />
				</template>
				{{ buttonLabel }}
			</NcButton>
		</div>
		<div v-show="isExpanded" class="override-panel">
			<div class="line">
				<NcTextField
					:id="contextPrefix + '-openai-url'"
					:model-value="localState.url"
					class="input"
					:label="t('integration_openai', 'Service URL override')"
					:placeholder="t('integration_openai', 'Example: {example}', { example: 'http://localhost:8080/v1' })"
					:show-trailing-button="!!localState.url"
					@update:model-value="updateUrl"
					@trailing-button-click="updateUrl('')">
					<template #icon>
						<EarthIcon :size="20" />
					</template>
				</NcTextField>
			</div>
			<NcNoteCard type="info">
				{{ t('integration_openai', 'With the current configuration, the target URL used to get the models for {aiTask} is:', { aiTask }) }}
				<br>
				<strong>{{ modelEndpointUrl }}</strong>
			</NcNoteCard>
			<div v-if="localState.url !== ''" class="line">
				<NcTextField
					:id="contextPrefix + '-openai-service-name'"
					:model-value="localState.service_name"
					class="input"
					:label="t('integration_openai', 'Service name (optional)')"
					:placeholder="t('integration_openai', 'Example: LocalAI of university ABC')"
					:show-trailing-button="!!localState.service_name"
					@update:model-value="updateServiceName"
					@trailing-button-click="updateServiceName('')" />
			</div>
			<div class="line">
				<NcInputField
					:id="contextPrefix + '-openai-request-timeout'"
					:model-value="localState.request_timeout"
					class="input"
					type="number"
					:label="t('integration_openai', 'Request timeout (seconds)')"
					:placeholder="t('integration_openai', 'Example: {example}', { example: '240' })"
					:show-trailing-button="!!localState.request_timeout"
					@update:model-value="updateRequestTimeout"
					@trailing-button-click="updateRequestTimeout('')">
					<template #icon>
						<TimerAlertOutlineIcon :size="20" />
					</template>
					<template #trailing-button-icon>
						<CloseIcon :size="20" />
					</template>
				</NcInputField>
			</div>
			<div v-show="localState.url !== ''" class="line column">
				<label>
					{{ t('integration_openai', 'Authentication method') }}
				</label>
				<div class="radios">
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:model-value="!localState.use_basic_auth"
						type="radio"
						button-variant-grouped="horizontal"
						:name="contextPrefix + '_auth_method'"
						@update:model-value="onCheckboxChanged(false, useBasicAuthKey)">
						{{ t('assistant', 'API key') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:model-value="localState.use_basic_auth"
						type="radio"
						button-variant-grouped="horizontal"
						:name="contextPrefix + '_auth_method'"
						@update:model-value="onCheckboxChanged(true, useBasicAuthKey)">
						{{ t('assistant', 'Basic Authentication') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
			<div v-show="localState.url === '' || !localState.use_basic_auth" class="line">
				<NcTextField
					:id="contextPrefix + '-openai-api-key'"
					:model-value="localState.api_key"
					class="input"
					type="password"
					:readonly="readonly"
					:label="t('integration_openai', 'API key')"
					:show-trailing-button="!!localState.api_key"
					@update:model-value="updateApiKey"
					@trailing-button-click="updateApiKey('')"
					@focus="$emit('focus')">
					<template #icon>
						<KeyOutlineIcon :size="20" />
					</template>
				</NcTextField>
			</div>
			<div v-show="localState.url !== '' && localState.use_basic_auth">
				<div class="line">
					<NcTextField
						:id="contextPrefix + '-openai-basic-user'"
						:model-value="localState.basic_user"
						class="input"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth user')"
						:show-trailing-button="!!localState.basic_user"
						@update:model-value="updateBasicUser"
						@trailing-button-click="updateBasicUser('')"
						@focus="$emit('focus')">
						<template #icon>
							<AccountOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
				<div class="line">
					<NcTextField
						:id="contextPrefix + '-openai-basic-password'"
						:model-value="localState.basic_password"
						class="input"
						type="password"
						:readonly="readonly"
						:label="t('integration_openai', 'Basic Auth password')"
						:show-trailing-button="!!localState.basic_password"
						@update:model-value="updateBasicPassword"
						@trailing-button-click="updateBasicPassword('')"
						@focus="$emit('focus')">
						<template #icon>
							<KeyOutlineIcon :size="20" />
						</template>
					</NcTextField>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import AccountOutlineIcon from 'vue-material-design-icons/AccountOutline.vue'
import UnfoldLessHorizontal from 'vue-material-design-icons/UnfoldLessHorizontal.vue'
import UnfoldMoreHorizontal from 'vue-material-design-icons/UnfoldMoreHorizontal.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import EarthIcon from 'vue-material-design-icons/Earth.vue'
import KeyOutlineIcon from 'vue-material-design-icons/KeyOutline.vue'
import TimerAlertOutlineIcon from 'vue-material-design-icons/TimerAlertOutline.vue'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcInputField from '@nextcloud/vue/components/NcInputField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'

export default {
	name: 'ServiceOverridePanel',

	components: {
		NcNoteCard,
		AccountOutlineIcon,
		UnfoldLessHorizontal,
		UnfoldMoreHorizontal,
		CloseIcon,
		EarthIcon,
		KeyOutlineIcon,
		TimerAlertOutlineIcon,
		NcButton,
		NcCheckboxRadioSwitch,
		NcInputField,
		NcTextField,
	},

	props: {
		contextPrefix: {
			type: String,
			required: true,
		},
		state: {
			type: Object,
			required: true,
		},
		readonly: {
			type: Boolean,
			default: true,
		},
		aiTask: {
			type: String,
			required: true,
		},
		defaultUrl: {
			type: String,
			required: true,
		},
	},

	emits: ['focus', 'input', 'sensitive-input', 'checkbox-changed'],

	data() {
		return {
			isExpanded: false,
		}
	},

	computed: {
		buttonLabel() {
			if (this.isExpanded) {
				return t('integration_openai', 'Hide override config for {aiTask}', { aiTask: this.aiTask })
			}
			return t('integration_openai', 'Show override config for {aiTask}', { aiTask: this.aiTask })
		},
		localState() {
			const prefix = this.contextPrefix
			return {
				url: this.state[`${prefix}_url`],
				service_name: this.state[`${prefix}_service_name`],
				request_timeout: this.state[`${prefix}_request_timeout`],
				use_basic_auth: this.state[`${prefix}_use_basic_auth`],
				api_key: this.state[`${prefix}_api_key`],
				basic_user: this.state[`${prefix}_basic_user`],
				basic_password: this.state[`${prefix}_basic_password`],
			}
		},
		useBasicAuthKey() {
			return `${this.contextPrefix}_use_basic_auth`
		},
		modelEndpointUrl() {
			const url = this.state[`${this.contextPrefix}_url`]
			if (url === '') {
				return this.defaultUrl
			}
			return url.replace(/\/*$/, '/models')
		},
	},

	methods: {
		updateUrl(value) {
			const prefix = this.contextPrefix
			this.$emit('sensitive-input', {
				[`${prefix}_url`]: (value ?? '').trim(),
			}, true)
		},
		updateServiceName(value) {
			const prefix = this.contextPrefix
			this.$emit('input', {
				[`${prefix}_service_name`]: value,
			})
		},
		updateRequestTimeout(value) {
			const prefix = this.contextPrefix
			this.$emit('input', {
				[`${prefix}_request_timeout`]: value,
			})
		},
		updateApiKey(value) {
			const prefix = this.contextPrefix
			const values = {}
			if (value !== 'dummyApiKey') {
				values[`${prefix}_api_key`] = value
			}
			this.$emit('sensitive-input', values, true)
		},
		updateBasicUser(value) {
			const prefix = this.contextPrefix
			const values = {}
			if (value) {
				values[`${prefix}_basic_user`] = value
			}
			this.$emit('sensitive-input', values, true)
		},
		updateBasicPassword(value) {
			const prefix = this.contextPrefix
			const values = {}
			if (value !== 'dummyPassword') {
				values[`${prefix}_basic_password`] = value
			}
			this.$emit('sensitive-input', values, true)
		},
		onCheckboxChanged(newValue, key) {
			this.$emit('checkbox-changed', newValue, key)
		},
	},
}
</script>

<style scoped lang="scss">
.override-panel {
	margin-left: 20px;
	margin-top: 12px;
	padding: 12px;
	border: 2px solid var(--color-border);
	border-radius: var(--border-radius);
}

.line {
	display: flex;
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

.radios {
	display: flex;
}
</style>
