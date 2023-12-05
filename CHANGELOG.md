# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## [Unreleased]

## 1.1.3 – 2023-11-15

### Added

- Optional Basic authentication for self-hosted (LocalAI) API wrapper services @mickenordin
- A comprehensive set of php-unit tests for speeding up development and preventing bugs creeping in @MB-Finski

## 1.1.2 – 2023-11-15

### Fixed

- Error with params for the completion endpoint @MB-Finski

### Added

- Admin setting for choosing the endpoint when using LocalAI @MB-Finski

## 1.1.1 – 2023-11-10

### Fixed

- Error introduced in transcription for 1.1.0 @MB-Finski

## 1.1.0 – 2023-11-09

### Added

- Enable clearing of prompt history from personal settings page @MB-Finski
- Enable limiting of api usage on per-user basis (user can use an own API key to bypass this) @MB-Finski
- Expose token limit as admin setting for text generation @MB-Finski

### Fixed

- Request no autocomplete for api key (in addition to disabling the field), which has caused issues for some users @MB-Finski
- Replace deprecated query() of IConfig in app container setup @MB-Finski
- Rewrite error handling logic, use proper http error codes @MB-Finski

## 1.0.13 – 2023-08-21

### Changed

- try to select gpt-3.5 when default model is not found when getting model list
- use selected default llm (in admin settings ) in the translation provider

### Fixed

- remove dashboard category in info.xml
- dynamically change labels depending if OpenAi or LocalAi is used

## 1.0.12 – 2023-08-04

### Added

- implement text processing providers (summarize, outline, free prompt and custom reformulate) @julien-nc
- admin setting to choose the API request timeout @julien-nc

### Fixed

- fix scrolling in the picker components @julien-nc
- allow usgin LocalAI without an API key @julien-nc
- save new admin default llm model when switching between OpenAI and LocalAI @julien-nc

## 1.0.11 – 2023-07-12

### Added

- Ethical AI rating [#24](https://github.com/nextcloud/integration_openai/issues/24) [#29](https://github.com/nextcloud/integration_openai/issues/29) @marcelklehr

## 1.0.10 – 2023-06-06

### Changed

- do not use OC functions/vars anymore @julien-nc

### Fixed

- App was not working if no admin api key is defined [#16](https://github.com/nextcloud/integration_openai/issues/16) @julien-nc

## 1.0.9 – 2023-05-31

### Added

- Allow using a custom service URL [#15](https://github.com/nextcloud/integration_openai/issues/15) [#18](https://github.com/nextcloud/integration_openai/issues/18) @julien-nc
- New admin settings to toggle each feature [#15](https://github.com/nextcloud/integration_openai/issues/15) @julien-nc

### Changed

- Bump max Nextcloud version to 28 @julien-nc
- Immediately update prompt history in chat/image picker components @julien-nc

## 1.0.8 – 2023-05-17

### Added

- translation provider @julien-nc
- allow user-specific API keys [#16](https://github.com/nextcloud/integration_openai/issues/16) @julien-nc

### Changed

- improve style of audio recorder @julien-nc
- use latest nextcloud/vue so the picker modal size can be set by the provider

## 1.0.7 – 2023-05-11

### Changed

- change regenerate icon @julien-nc
- disable inputs during generation/regeneration @julien-nc
- set prompt history bubble max width, add title with full text @julien-nc

## 1.0.6 – 2023-05-09

### Added

- implement OCP\SpeechToText\ISpeechToTextProvider @julien-nc

### Changed

- improve style of the whisper picker component, respect dark theme @julien-nc
- improve style of the GPT and Dall-e picker components @julien-nc
- remember last image size for Dall-e picker @julien-nc
- implement 2 step flow in GPT and Dall-e picker components, preview/adjust before submitting @julien-nc

## 1.0.5 – 2023-04-26

### Added

- add prompt history for text and image generation @julien-nc

### Changed

- add padding in the picker component because the one in the picker will be removed @julien-nc
- get rid of `@nextcloud/vue-richtext` dependency as the build bug has been solved in the nextcloud webpack config @julien-nc

## 1.0.4 – 2023-04-07
### Added
- gpt picker option to set max number of tokens (~words) to generate

## 1.0.3 – 2023-04-07
### Added
- 'include query' option in gpt custom picker
- link preview for Dall-e image generations
- support for gpt-3.5
- admin setting to choose default completion model
- save last used model as default user-specific default completion model
- Whisper speech-to-text smart picker provider to translate/transcode

### Fixed
- show error message of failing API request responses

## 1.0.2 – 2023-03-03
### Changed
- improve design
- use more NC components

## 1.0.0 – 2022-12-19
### Added
* the app
