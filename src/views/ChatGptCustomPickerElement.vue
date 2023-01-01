<template>
	<div v-if="show" id="chatgpt-picker-modal-wrapper">
		<NcModal
			size="large"
			:container="'#chatgpt-picker-modal-wrapper'"
			@close="onCancel">
			<div class="chatgpt-picker-modal-content">
				<h2>
					{{ t('integration_openai', 'Get a ChatGPT answer') }}
					<a class="attribution"
						target="_blank"
						href="https://openai.com">
						{{ poweredByTitle }}
					</a>
				</h2>
				<div class="input-wrapper">
					<input ref="search-input"
						v-model="query"
						type="text"
						:placeholder="inputPlaceholder"
						@keydown.enter="onInputEnter"
						@keyup.esc="onCancel">
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
						<select
							id="model"
							v-model="completionModel">
							<option v-for="m in models"
								:key="m.id"
								:value="m.id">
								{{ m.id }} ({{ m.owned_by }})
							</option>
						</select>
					</div>
				</div>
				<div class="footer">
					<NcButton @click="onCancel">
						{{ t('integration_openai', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ChatGptCustomPickerElement',

	components: {
		NcModal,
		NcButton,
		NcLoadingIcon,
		ChevronRightIcon,
		ChevronDownIcon,
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
			show: true,
			query: '',
			loading: false,
			models: [],
			inputPlaceholder: t('integration_openai', 'What is the matter with putting pineapple on pizzas?'),
			poweredByTitle: t('integration_openai', 'Powered by OpenAI'),
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
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
		this.getModels()
	},

	methods: {
		focusOnInput() {
			this.$nextTick(() => {
				this.$refs['search-input']?.focus()
			})
		},
		getModels() {
			const url = generateUrl('/apps/integration_openai/models')
			return axios.get(url)
				.then((response) => {
					this.models = response.data?.data
					this.completionModel = response.data?.default_model_id
				})
				.catch((error) => {
					console.error(error)
				})
		},
		onCancel() {
			this.show = false
			this.$emit('cancel')
		},
		onSubmit(url) {
			this.show = false
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
				params.model = this.completionModel
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
.chatgpt-picker-modal-content {
	width: 100%;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 16px;

	h2 {
		display: flex;
		align-items: center;
		.attribution {
			margin-left: 16px;
		}
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
			select {
				width: 300px;
			}
		}

		input[type=number] {
			appearance: initial !important;
			-moz-appearance: initial !important;
			-webkit-appearance: initial !important;
		}
	}

	.footer {
		width: 100%;
		margin-top: 8px;
		display: flex;
		justify-content: end;
	}
}
</style>
