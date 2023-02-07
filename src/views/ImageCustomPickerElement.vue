<template>
	<div class="dalle-picker-content">
		<h2>
			{{ t('integration_openai', 'Generate an image with DALL·E 2') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com/dall-e-2/">
			{{ poweredByTitle }}
		</a>
		<div class="input-wrapper">
			<input ref="dalle-search-input"
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
					{{ t('integration_openai', 'Number of images to generate (1-10)') }}
				</label>
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
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ImageCustomPickerElement',

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
			query: '',
			loading: false,
			inputPlaceholder: t('integration_openai', 'cyberpunk pizza with pineapple, cats fighting with lightsabers'),
			poweredByTitle: t('integration_openai', 'Powered by DALL·E 2 from OpenAI'),
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
				this.$refs['dalle-search-input']?.focus()
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
					const data = response.data?.data
					if (data && data.length && data.length > 0) {
						const value = data.filter(d => !!d.url).map(d => d.url).join(' , ')
						this.onSubmit(value)
					} else {
						this.error = response.data.error
					}
				})
				.catch((error) => {
					console.error('OpenAI image request error', error)
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

			input,
			select {
				width: 200px;
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
