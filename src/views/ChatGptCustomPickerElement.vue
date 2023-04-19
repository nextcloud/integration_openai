<template>
	<div class="chatgpt-picker-content">
		<h2>
			{{ t('integration_openai', 'ChatGPT-like text generation') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com">
			{{ poweredByTitle }}
		</a>
		<div class="input-wrapper">
			<NcTextField
				ref="chatgpt-search-input"
				:value.sync="query"
				:label="inputPlaceholder"
				:show-trailing-button="!!query"
				@keydown.enter="onInputEnter"
				@trailing-button-click="query = ''" />
		</div>
		<div class="footer">
			<NcButton class="advanced-button"
				type="tertiary"
				:aria-label="t('integration_openai', 'Show/hide advanced options')"
				@click="showAdvanced = !showAdvanced">
				<template #icon>
					<component :is="showAdvancedIcon" />
				</template>
				{{ t('integration_openai', 'Advanced options') }}
			</NcButton>
			<NcButton
				type="primary"
				:aria-label="t('integration_openai', 'Generate text with OpenAI')"
				:disabled="loading || !query || !selectedModel"
				@click="onInputEnter">
				{{ t('integration_openai', 'Generate') }}
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<ArrowRightIcon v-else />
				</template>
			</NcButton>
		</div>
		<div v-show="showAdvanced" class="advanced">
			<NcSelect
				class="prompt-select"
				:placeholder="t('integration_openai', 'Recent prompts...')"
				:options="formattedPrompts"
				input-id="openai-prompt-select"
				@input="onPromptSelected" />
			<div class="line">
				<div class="spacer" />
				<NcCheckboxRadioSwitch
					class="include-query"
					:checked.sync="includeQuery">
					{{ t('integration_openai', 'Include the input text in the result') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="line">
				<label for="nb-results">
					{{ t('integration_openai', 'How many results to generate') }}
				</label>
				<div class="spacer" />
				<input
					id="nb-results"
					v-model="completionNumber"
					type="number"
					min="1"
					max="10"
					step="1">
			</div>
			<div class="line">
				<label for="openai-completion-model-select">
					{{ t('integration_openai', 'Model to use') }}
				</label>
				<a :title="t('integration_openai', 'More information about OpenAI models')"
					href="https://beta.openai.com/docs/models"
					target="_blank">
					<NcButton type="tertiary"
						:aria-label="t('integration_openai', 'More information about OpenAI models')">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
				<div class="spacer" />
				<NcSelect
					v-model="selectedModel"
					class="model-select"
					:options="formattedModels"
					input-id="openai-completion-model-select" />
			</div>
			<div class="line">
				<label for="max-tokens">
					{{ t('integration_openai', 'Maximum number of tokens to generate') }}
				</label>
				<div class="spacer" />
				<input
					id="max-tokens"
					v-model="maxTokens"
					type="number"
					min="10"
					max="100000"
					step="1">
			</div>
		</div>
	</div>
</template>

<script>
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'ChatGptCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		ChevronRightIcon,
		ChevronDownIcon,
		HelpCircleIcon,
		ArrowRightIcon,
	},

	props: {
		providerId: {
			type: String,
			required: true,
		},
		accessible: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			query: '',
			loading: false,
			models: [],
			inputPlaceholder: t('integration_openai', 'What is the matter with putting pineapple on pizza?'),
			poweredByTitle: t('integration_openai', 'by OpenAI'),
			modelPlaceholder: t('integration_openai', 'Choose a model'),
			showAdvanced: false,
			selectedModel: null,
			includeQuery: false,
			completionNumber: 1,
			maxTokens: 1000,
			prompts: null,
		}
	},

	computed: {
		showAdvancedIcon() {
			return this.showAdvanced
				? ChevronDownIcon
				: ChevronRightIcon
		},
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
		formattedPrompts() {
			if (this.prompts) {
				return this.prompts.slice().sort((a, b) => {
					const tsA = a.timestamp
					const tsB = b.timestamp
					return tsA > tsB
						? -1
						: tsA < tsB
							? 1
							: 0
				}).map(p => {
					return {
						id: p.id,
						value: p.value,
						label: p.value,
					}
				})
			}
			return []
		},
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
		this.getModels()
		this.getPromptHistory()
	},

	methods: {
		focusOnInput() {
			setTimeout(() => {
				this.$refs['chatgpt-search-input'].$el.getElementsByTagName('input')[0]?.focus()
			}, 300)
		},
		getPromptHistory() {
			const params = {
				params: {
					type: 1,
				},
			}
			const url = generateUrl('/apps/integration_openai/prompts')
			return axios.get(url, params)
				.then((response) => {
					this.prompts = response.data
				})
				.catch((error) => {
					console.error(error)
				})
		},
		onPromptSelected(prompt) {
			this.query = prompt.value
			this.focusOnInput()
		},
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					const defaultModelId = response.data?.default_completion_model_id
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
		saveModel(modelId) {
			const req = {
				values: {
					default_completion_model_id: modelId,
				},
			}
			const url = generateUrl('/apps/integration_openai/config')
			return axios.put(url, req)
				.then((response) => {
				})
				.catch((error) => {
					showError(
						t('integration_openai', 'Failed to save OpenAI default model ID')
						+ ': ' + error.response?.request?.responseText
					)
				})
		},
		onSubmit(url) {
			this.$emit('submit', url)
		},
		onInputEnter() {
			if (this.query === '') {
				return
			}
			this.loading = true
			const params = {
				prompt: this.query,
				n: this.completionNumber,
				maxTokens: this.maxTokens,
			}
			if (this.selectedModel) {
				params.model = this.selectedModel.id
				this.saveModel(this.selectedModel.id)
			}
			const url = generateUrl('/apps/integration_openai/completions')
			return axios.post(url, params)
				.then((response) => {
					const data = response.data
					if (data.choices && data.choices.length && data.choices.length > 0) {
						this.processCompletion(data.choices)
					} else {
						this.error = response.data.error
					}
				})
				.catch((error) => {
					console.error('OpenAI completions request error', error)
					showError(
						t('integration_openai', 'OpenAI error') + ': '
							+ (error.response?.data?.body?.error?.message ?? t('integration_openai', 'Unknown OpenAI API error'))
					)
				})
				.then(() => {
					this.loading = false
				})
		},
		processCompletion(choices) {
			const answers = this.selectedModel.id.startsWith('gpt-')
				? choices.filter(c => !!c.message?.content).map(c => c.message?.content.replace(/^\s+|\s+$/g, ''))
				: choices.filter(c => !!c.text).map(c => c.text.replace(/^\s+|\s+$/g, ''))
			if (answers.length > 0) {
				if (answers.length === 1) {
					const result = this.includeQuery
						? t('integration_openai', 'Query') + '\n' + this.query + '\n\n' + t('integration_openai', 'Result') + '\n' + answers[0]
						: answers[0]
					this.onSubmit(result)
				} else {
					const multiAnswers = answers.map((a, i) => {
						return t('integration_openai', 'Result {index}', { index: i + 1 }) + '\n' + a
					})
					const result = this.includeQuery
						? t('integration_openai', 'Query') + '\n' + this.query + '\n\n' + multiAnswers.join('\n\n')
						: multiAnswers.join('\n\n')
					this.onSubmit(result)
				}
			}
		},
	},
}
</script>

<style scoped lang="scss">
.chatgpt-picker-content {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 12px 16px 16px 16px;

	h2 {
		display: flex;
		align-items: center;
	}

	.spacer {
		flex-grow: 1;
	}

	.attribution {
		color: var(--color-text-maxcontrast);
		padding-bottom: 8px;
	}

	.input-wrapper {
		display: flex;
		align-items: center;
		width: 100%;
	}

	.prompt-select {
		width: 100%;
		margin-top: 4px;
	}

	.footer {
		width: 100%;
		display: flex;
		align-items: center;
		justify-content: end;
		margin-top: 12px;
		> * {
			margin-left: 4px;
		}
	}

	.advanced {
		width: 100%;
		padding: 12px 0;
		.line {
			display: flex;
			align-items: center;
			margin-top: 8px;

			input {
				width: 200px;
			}
			.model-select {
				width: 300px;
			}
		}

		input[type=number] {
			width: 80px;
			appearance: initial !important;
			-moz-appearance: initial !important;
			-webkit-appearance: initial !important;
		}

		.include-query {
			margin-right: 16px;
		}
	}
}
</style>
