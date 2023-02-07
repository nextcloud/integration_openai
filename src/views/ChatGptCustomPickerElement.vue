<template>
	<div class="chatgpt-picker-content">
		<h2>
			{{ t('integration_openai', 'Get a ChatGPT answer') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com">
			{{ poweredByTitle }}
		</a>
		<div class="input-wrapper">
			<input ref="chatgpt-search-input"
				v-model="query"
				type="text"
				:placeholder="inputPlaceholder"
				@keydown.enter="onInputEnter">
			<NcLoadingIcon v-if="loading"
				:size="20"
				:title="t('integration_openai', 'Loading')" />
			<NcButton v-else @click="onInputEnter">
				{{ t('integration_openai', 'Submit') }}
			</NcButton>
		</div>
		<NcButton class="advanced-button"
			@click="showAdvanced = !showAdvanced">
			<template #icon>
				<component :is="showAdvancedIcon" />
			</template>
			{{ t('integration_openai', 'Advanced options') }}
		</NcButton>
		<div v-show="showAdvanced" class="advanced">
			<div class="line">
				<label for="number">
					{{ t('integration_openai', 'How many completions to generate') }}
				</label>
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
				<NcMultiselect
					:value="completionModel"
					class="model-select"
					label="label"
					track-by="id"
					:placeholder="modelPlaceholder"
					:options="formattedModels"
					:user-select="false"
					:internal-search="true"
					@input="onModelSelected" />
				<a v-tooltip.top="{ content: t('integration_openai', 'More information about OpenAI models') }"
					href="https://beta.openai.com/docs/models"
					target="_blank">
					<NcButton>
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
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import HelpCircleIcon from 'vue-material-design-icons/HelpCircle.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcMultiselect from '@nextcloud/vue/dist/Components/NcMultiselect.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

import Tooltip from '@nextcloud/vue/dist/Directives/Tooltip.js'
import Vue from 'vue'
Vue.directive('tooltip', Tooltip)

export default {
	name: 'ChatGptCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcMultiselect,
		ChevronRightIcon,
		ChevronDownIcon,
		HelpCircleIcon,
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
			inputPlaceholder: t('integration_openai', 'What is the matter with putting pineapple on pizzas?'),
			poweredByTitle: t('integration_openai', 'Powered by OpenAI'),
			modelPlaceholder: t('integration_openai', 'Choose a model'),
			showAdvanced: false,
			completionModel: null,
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
						...m,
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
				this.$refs['chatgpt-search-input']?.focus()
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
						this.completionModel = {
							...defaultModel,
							label: defaultModel.id + ' (' + defaultModel.owned_by + ')',
						}
					}
				})
				.catch((error) => {
					console.error(error)
				})
		},
		onModelSelected(model) {
			if (model) {
				this.completionModel = model
			}
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
			if (this.completionModel) {
				params.model = this.completionModel.id
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
					this.onSubmit(answers[0])
				} else {
					const multiAnswers = answers.map((a, i) => {
						return '-- ' + t('integration_openai', 'Answer {index}', { index: i + 1 }) + '\n\n' + a
					})
					this.onSubmit(multiAnswers.join('\n\n'))
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

	.attribution {
		padding-bottom: 8px;
	}

	.input-wrapper {
		display: flex;
		align-items: center;
		width: 100%;
		input {
			flex-grow: 1;
		}
	}

	.advanced-button {
		align-self: start;
		margin-top: 12px;
	}

	.advanced {
		width: 100%;
		padding: 12px 0;
		.line {
			display: flex;

			label {
				flex-grow: 1;
			}

			input {
				width: 200px;
			}
			.model-select {
				width: 300px;
			}
		}

		input[type=number] {
			appearance: initial !important;
			-moz-appearance: initial !important;
			-webkit-appearance: initial !important;
		}
	}
}
</style>
