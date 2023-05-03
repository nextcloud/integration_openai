<template>
	<div class="openai-picker-content">
		<h2>
			{{ t('integration_openai', 'AI speech-to-text') }}
		</h2>
		<a class="attribution"
			target="_blank"
			href="https://openai.com">
			{{ poweredByTitle }}
		</a>
		<audio-recorder
			class="recorder"
			:attempts="1"
			:time="120"
			:show-download-button="true"
			:show-upload-button="false"
			:after-recording="onRecordEnd" />
		<div class="form-wrapper">
			<div class="line justified">
				<div class="radios">
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:checked.sync="mode"
						type="radio"
						value="transcribe"
						button-variant-grouped="horizontal"
						name="mode">
						{{ t('integration_openai', 'Transcribe') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:button-variant="true"
						:checked.sync="mode"
						type="radio"
						value="translate"
						button-variant-grouped="horizontal"
						name="mode">
						{{ t('integration_openai', 'Translate (only to English)') }}
					</NcCheckboxRadioSwitch>
				</div>
			</div>
		</div>
		<div class="footer">
			<span v-if="error" class="error">
				{{ error }}
			</span>
			<NcButton
				type="primary"
				:aria-label="submitButtonLabel"
				:disabled="loading || audio === null"
				@click="onInputEnter">
				<template #icon>
					<NcLoadingIcon v-if="loading"
						:size="20" />
					<ArrowRightIcon v-else />
				</template>
				{{ submitButtonLabel }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import VueAudioRecorder from 'vue2-audio-recorder'

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'

import Vue from 'vue'
Vue.use(VueAudioRecorder)

export default {
	name: 'WhisperCustomPickerElement',

	components: {
		NcButton,
		NcLoadingIcon,
		NcCheckboxRadioSwitch,
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
			loading: false,
			poweredByTitle: t('integration_openai', 'by OpenAI with Whisper'),
			mode: 'transcribe',
			audio: null,
			error: null,
		}
	},

	computed: {
		submitButtonLabel() {
			return this.mode === 'transcribe'
				? t('integration_openai', 'Transcribe')
				: t('integration_openai', 'Translate')
		},
	},

	watch: {
	},

	mounted() {
	},

	methods: {
		async onRecordEnd(e) {
			const readBlob = (blob) => {
				const reader = new FileReader()
				return new Promise((resolve) => {
					reader.addEventListener('load', () => {
						resolve(reader.result)
					})
					reader.readAsDataURL(blob)
				})
			}

			try {
				this.audio = await readBlob(e.blob)
			} catch (e) {
				console.error('recording error', e.message)
			}
		},
		onSubmit(url) {
			this.$emit('submit', url)
		},
		onInputEnter() {
			this.loading = true
			this.error = null
			const params = {
				translate: this.mode === 'translate',
				audioBase64: this.audio,
			}
			const url = generateUrl('/apps/integration_openai/audio/transcriptions')
			return axios.post(url, params)
				.then((response) => {
					console.debug('whisper response', response.data)
					this.onSubmit(response.data.text)
				})
				.catch((error) => {
					console.debug('openai whisper request error', error)
					showError(
						t('integration_openai', 'Failed to get transcription/translation')
						+ ': ' + (error.response?.data?.body?.error?.message ?? error.response?.data?.error)
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
.openai-picker-content {
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
		padding-bottom: 12px;
	}

	.form-wrapper {
		display: flex;
		flex-direction: column;
		align-items: center;
		width: 100%;
		margin: 8px 0;
		.radios {
			display: flex;
		}
	}

	.line {
		display: flex;
		align-items: center;
		margin-top: 8px;
		width: 100%;
		&.justified {
			justify-content: center;
		}
	}

	.footer {
		display: flex;
		align-items: center;
		justify-content: end;
		width: 100%;

		.error {
			margin-right: 12px;
		}
	}

	::v-deep .recorder {
		background-color: var(--color-main-background) !important;
		.ar-content * {
			color: var(--color-main-text) !important;
		}
		.ar-icon {
			background-color: var(--color-main-background) !important;
			fill: var(--color-main-text) !important;
		}
		.ar-recorder__time-limit {
			position: unset !important;
		}
		.ar-player__time {
			font-size: 14px;
		}
		.ar-records {
			height: unset !important;
			&__record--selected {
				background-color: var(--color-background-dark) !important;
				.ar-icon {
					background-color: var(--color-background-dark) !important;
				}
			}
		}
	}
}
</style>
