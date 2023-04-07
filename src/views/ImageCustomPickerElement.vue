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
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcTextField from '@nextcloud/vue/dist/Components/NcTextField.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

export default {
	name: 'ImageCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		ChevronRightIcon,
		ChevronDownIcon,
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
			inputPlaceholder: t('integration_openai', 'cyberpunk pizza with pineapple, cats fighting with lightsabers'),
			poweredByTitle: t('integration_openai', 'by OpenAI with DALL·E 2'),
			showAdvanced: false,
			imageNumber: 1,
			imageSize: '1024x1024',
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
	},

	methods: {
		focusOnInput() {
			setTimeout(() => {
				this.$refs['dalle-search-input'].$el.getElementsByTagName('input')[0]?.focus()
			}, 300)
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
				n: this.imageNumber,
				size: this.imageSize,
			}
			const url = generateUrl('/apps/integration_openai/images/generations')
			return axios.post(url, params)
				.then((response) => {
					const hash = response.data?.hash
					if (hash && hash.length && hash.length > 0) {
						const link = window.location.protocol + '//' + window.location.host
							+ generateUrl('/apps/integration_openai/i/{hash}', { hash })
						this.onSubmit(link)
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
