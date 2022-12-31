<template>
	<div v-if="show" id="image-picker-modal-wrapper">
		<NcModal
			size="large"
			:container="'#image-picker-modal-wrapper'"
			@close="onCancel">
			<div class="image-picker-modal-content">
				<h2>
					{{ t('integration_openai', 'Generate an image with OpenAI') }}
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
			inputPlaceholder: t('integration_openai', 'cyberpunk pizza with pineapple, cats fighting with lightsabers'),
			poweredByTitle: t('integration_openai', 'Powered by OpenAI'),
		}
	},

	computed: {
	},

	watch: {
	},

	mounted() {
		this.focusOnInput()
	},

	methods: {
		focusOnInput() {
			this.$nextTick(() => {
				this.$refs['search-input']?.focus()
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
			}
			const url = generateUrl('/apps/integration_openai/images/generations')
			return axios.post(url, params)
				.then((response) => {
					console.debug('image generation response', response.data)
					const data = response.data?.data
					if (data && data.length && data.length > 0 && data[0].url) {
						this.onSubmit(data[0].url)
					} else {
						this.error = response.data.error
					}
				})
				.catch((error) => {
					console.debug('OpenAI image request error', error)
				})
				.then(() => {
					this.loading = false
				})
		},
	},
}
</script>

<style scoped lang="scss">
.image-picker-modal-content {
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

	.footer {
		width: 100%;
		margin-top: 8px;
		display: flex;
		justify-content: end;
	}
}
</style>
