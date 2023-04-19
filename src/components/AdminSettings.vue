<template>
	<div id="openai_prefs" class="section">
		<h2>
			<OpenAiIcon class="icon" />
			{{ t('integration_openai', 'OpenAI integration') }}
		</h2>
		<div id="openai-content">
			<div class="line">
				<label for="openai-api-key">
					<KeyIcon :size="20" class="icon" />
					{{ t('integration_openai', 'OpenAI API key') }}
				</label>
				<input id="openai-api-key"
					v-model="state.api_key"
					type="password"
					:readonly="readonly"
					:placeholder="t('integration_openai', 'your API key')"
					@input="onInput"
					@focus="readonly = false">
			</div>
			<p class="settings-hint">
				<InformationOutlineIcon :size="20" class="icon" />
				{{ t('integration_openai', 'You can create a free API key in your OpenAI account settings:') }}
				&nbsp;
				<a :href="apiKeyUrl" target="_blank" class="external">
					{{ apiKeyUrl }}
				</a>
			</p>
			<div v-if="models"
				class="line">
				<label for="size">
					{{ t('integration_openai', 'Default completion model to use') }}
				</label>
				<div class="spacer" />
				<NcSelect
					v-model="selectedModel"
					class="model-select"
					:options="formattedModels"
					:no-wrap="true"
					input-id="openai-model-select"
					@input="onModelSelected" />
				<a :title="t('integration_openai', 'More information about OpenAI models')"
					href="https://beta.openai.com/docs/models"
					target="_blank">
					<NcButton type="tertiary">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
			</div>
		</div>
	</div>
</template>

<script>
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import OpenAiIcon from './icons/OpenAiIcon.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'

import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import { delay } from '../utils.js'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'AdminSettings',

	components: {
		OpenAiIcon,
		KeyIcon,
		InformationOutlineIcon,
		HelpCircleIcon,
		NcButton,
		NcSelect,
	},

	props: [],

	data() {
		return {
			state: loadState('integration_openai', 'admin-config'),
			// to prevent some browsers to fill fields with remembered passwords
			readonly: true,
			models: null,
			selectedModel: null,
			apiKeyUrl: 'https://platform.openai.com/account/api-keys',
		}
	},

	computed: {
		formattedModels() {
			if (this.models) {
				return this.models.map(m => {
					return {
						id: m.id,
						value: m.id,
						label: m.id + ' (' + m.owned_by + ')',
					}
				})
			}
			return []
		},
	},

	watch: {
	},

	mounted() {
		if (this.state.api_key) {
			this.getModels()
		}
	},

	methods: {
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					const defaultModelId = this.state.default_completion_model_id ?? response.data?.default_completion_model_id
					const defaultModel = this.models.find(m => m.id === defaultModelId)
					const modelToSelect = defaultModel ?? this.models[0] ?? null
					if (modelToSelect) {
						this.selectedModel = {
							id: modelToSelect.id,
							value: modelToSelect.id,
							label: modelToSelect.id + ' (' + modelToSelect.owned_by + ')',
						}
					}
				})
				.catch((error) => {
					console.error(error)
				})
		},
		onModelSelected(selected) {
			this.state.default_completion_model_id = selected.id
			this.saveOptions({ default_completion_model_id: this.state.default_completion_model_id })
		},
		onInput() {
			delay(() => {
				this.saveOptions({
					api_key: this.state.api_key,
				}).then(() => {
					this.models = null
					if (this.state.api_key) {
						this.getModels()
					}
				})
			}, 2000)()
		},
		saveOptions(values) {
			const req = {
				values,
			}
			const url = generateUrl('/apps/integration_openai/admin-config')
			return axios.put(url, req)
				.then((response) => {
					showSuccess(t('integration_openai', 'OpenAI admin options saved'))
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to save OpenAI admin options')
						+ ': ' + error.response?.request?.responseText
					)
				})
		},
	},
}
</script>

<style scoped lang="scss">
#openai_prefs {
	#openai-content {
		margin-left: 40px;
	}
	h2,
	.line,
	.settings-hint {
		display: flex;
		align-items: center;
		margin-top: 12px;
		.icon {
			margin-right: 4px;
		}
	}

	h2 .icon {
		margin-right: 8px;
	}

	.line {
		> label {
			width: 300px;
			display: flex;
			align-items: center;
		}
		> input {
			width: 300px;
		}
	}

	.model-select {
		min-width: 350px;
	}
}
</style>
