<template>
	<div class="dalle-picker-content">
		<h2>
			{{ t('integration_openai', 'AI image generation') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com/dall-e-2/">
			{{ poweredByTitle }}
		</a>
		<div class="input-wrapper">
			<NcTextField
				ref="dalle-search-input"
				:value.sync="query"
				:label="inputPlaceholder"
				:show-trailing-button="!!query"
				@keydown.enter="generate"
				@trailing-button-click="query = ''" />
		</div>
		<div v-if="reference === null || query === ''"
			class="prompts">
			<NcUserBubble v-for="p in prompts"
				:key="p.id"
				:size="30"
				avatar-image="icon-history"
				:display-name="p.value"
				@click="query = p.value" />
		</div>
		<ImageReferenceWidget v-if="reference !== null"
			:rich-object="reference.richObject"
			orientation="horizontal" />
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
				type="secondary"
				:aria-label="t('integration_openai', 'Preview images with OpenAI')"
				:disabled="loading || !query"
				@click="generate">
				{{ previewButtonLabel }}
				<template #icon>
					<NcLoadingIcon v-if="loading" />
					<EyeRefreshIcon v-else-if="resultUrl !== null" />
					<EyeIcon v-else />
				</template>
			</NcButton>
			<NcButton v-if="resultUrl !== null"
				type="primary"
				:aria-label="t('integration_openai', 'Submit the current preview')"
				:disabled="loading"
				@click="submit">
				{{ t('integration_openai', 'Send') }}
				<template #icon>
					<ArrowRightIcon />
				</template>
			</NcButton>
		</div>
		<div v-show="showAdvanced" class="advanced">
			<div class="line">
				<label for="number">
					{{ t('integration_openai', 'Number of images to generate (1-10)') }}
				</label>
				<div class="spacer" />
				<input
					id="number"
					v-model="imageNumber"
					type="number"
					min="1"
					max="10"
					step="1">
			</div>
			<div class="line">
				<label for="size">
					{{ t('integration_openai', 'Size of the generated images') }}
				</label>
				<div class="spacer" />
				<select
					id="size"
					v-model="imageSize">
					<option value="256x256">
						256x256 px
					</option>
					<option value="512x512">
						512x512 px
					</option>
					<option value="1024x1024">
						1024x1024 px
					</option>
				</select>
			</div>
		</div>
	</div>
</template>

<script>
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeRefreshIcon from 'vue-material-design-icons/EyeRefresh.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'
import NcSelect from '@nextcloud/vue/dist/Components/NcSelect.js'
import NcUserBubble from '@nextcloud/vue/dist/Components/NcUserBubble.js'

import ImageReferenceWidget from './ImageReferenceWidget.vue'

import axios from '@nextcloud/axios'
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'ImageCustomPickerElement',

	components: {
		ImageReferenceWidget,
		NcButton,
		NcLoadingIcon,
		NcTextField,
		NcSelect,
		ChevronRightIcon,
		ChevronDownIcon,
		ArrowRightIcon,
		NcUserBubble,
		EyeIcon,
		EyeRefreshIcon,
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
			resultUrl: null,
			reference: null,
			inputPlaceholder: t('integration_openai', 'cyberpunk pizza with pineapple, cats fighting with lightsabers'),
			poweredByTitle: t('integration_openai', 'by OpenAI with DALL·E 2'),
			showAdvanced: false,
			imageNumber: 1,
			imageSize: '1024x1024',
			prompts: null,
		}
	},

	computed: {
		showAdvancedIcon() {
			return this.showAdvanced
				? ChevronDownIcon
				: ChevronRightIcon
		},
		previewButtonLabel() {
			return this.resultUrl !== null
				? t('integration_openai', 'Regenerate')
				: t('integration_openai', 'Preview')
		},
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
		this.getPromptHistory()
		this.getLastImageSize()
	},

	methods: {
		focusOnInput() {
			setTimeout(() => {
				this.$refs['dalle-search-input'].$el.getElementsByTagName('input')[0]?.focus()
			}, 300)
		},
		getPromptHistory() {
			const params = {
				params: {
					type: 0,
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
		getLastImageSize() {
			const url = generateUrl('/apps/integration_openai/last-image-size')
			return axios.get(url)
				.then((response) => {
					this.imageSize = response.data
				})
				.catch((error) => {
					console.error(error)
				})
		},
		submit() {
			this.$emit('submit', this.resultUrl)
		},
		generate() {
			if (this.query === '') {
				return
			}
			this.loading = true
			const params = {
				prompt: this.query,
				n: parseInt(this.imageNumber),
				size: this.imageSize,
			}
			const url = generateUrl('/apps/integration_openai/images/generations')
			return axios.post(url, params)
				.then((response) => {
					const hash = response.data?.hash
					if (hash && hash.length && hash.length > 0) {
						this.resultUrl = window.location.protocol + '//' + window.location.host
							+ generateUrl('/apps/integration_openai/i/{hash}', { hash })
						this.resolveResult()
					} else {
						this.error = response.data?.error ?? t('integration_openai', 'Unknown error')
					}
				})
				.catch((error) => {
					console.error('OpenAI image request error', error)
					showError(
						t('integration_openai', 'OpenAI error') + ': '
							+ (error.response?.data?.body?.error?.message ?? t('integration_openai', 'Unknown OpenAI API error'))
					)
				})
				.then(() => {
					this.loading = false
				})
		},
		resolveResult() {
			this.loading = true
			this.abortController = new AbortController()
			axios.get(generateOcsUrl('references/resolve', 2) + '?reference=' + encodeURIComponent(this.resultUrl), {
				signal: this.abortController.signal,
			})
				.then((response) => {
					this.reference = response.data.ocs.data.references[this.resultUrl]
				})
				.catch((error) => {
					console.error(error)
				})
				.then(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.dalle-picker-content {
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

	.prompts {
		margin-top: 8px;
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		> * {
			margin-right: 8px;
		}
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

			input,
			select {
				width: 200px;
			}
		}

		input[type=number] {
			width: 80px;
			appearance: initial !important;
			-moz-appearance: initial !important;
			-webkit-appearance: initial !important;
		}
	}
}
</style>
