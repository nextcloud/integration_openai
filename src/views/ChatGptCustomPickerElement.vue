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
				@click="showAdvanced = !showAdvanced">
				<template #icon>
					<component :is="showAdvancedIcon" />
				</template>
				{{ t('integration_openai', 'Advanced options') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="loading || !query"
				@click="onInputEnter">
				{{ t('integration_openai', 'Generate') }}
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<ArrowRightIcon v-else />
				</template>
			</NcButton>
		</div>
		<div v-show="showAdvanced" class="advanced">
			<div class="line">
				<div class="spacer" />
				<NcCheckboxRadioSwitch
					class="include-query"
					:checked.sync="includeQuery">
					{{ t('integration_openai', 'Include the input text in the result') }}
				</NcCheckboxRadioSwitch>
			</div>
			<div class="line">
				<label for="number">
					{{ t('integration_openai', 'How many results to generate') }}
				</label>
				<div class="spacer" />
				<input
					id="number"
					v-model="completionNumber"
					type="number"
					min="1"
					max="10"
					step="1">
			</div>
			<div class="line">
				<label for="size">
					{{ t('integration_openai', 'Model to use') }}
				</label>
				<a :title="t('integration_openai', 'More information about OpenAI models')"
					href="https://beta.openai.com/docs/models"
					target="_blank">
					<NcButton type="tertiary">
						<template #icon>
							<HelpCircleIcon />
						</template>
					</NcButton>
				</a>
				<div class="spacer" />
				<NcSelect
					v-model="selectedModel"
					:options="formattedModels"
					input-id="openai-model-select" />
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
import NcMultiselect from '@nextcloud/vue/dist/Components/NcMultiselect.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ChatGptCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcMultiselect,
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
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
		this.getModels()
	},

	methods: {
		focusOnInput() {
			setTimeout(() => {
				this.$refs['chatgpt-search-input'].$el.getElementsByTagName('input')[0]?.focus()
			}, 300)
		},
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					const defaultModelId = response.data?.default_model_id
					const defaultModel = this.models.find(m => m.id === defaultModelId)
					if (defaultModel) {
						this.selectedModel = {
							id: defaultModel.id,
							value: defaultModel.id,
							label: defaultModel.id + ' (' + defaultModel.owned_by + ')',
						}
					}
				})
				.catch((error) => {
					console.error(error)
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
			}
			if (this.selectedModel) {
				params.model = this.selectedModel.id
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
				})
				.then(() => {
					this.loading = false
				})
		},
		processCompletion(choices) {
			const answers = choices.filter(c => !!c.text).map(c => c.text.replace(/^\s+|\s+$/g, ''))
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
	//padding: 16px;

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
