<!--
  - SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Change Log
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

## 4.2.0 - 2026-01-16

### Added

- Support multiple providers @lukasdotcom [#311](https://github.com/nextcloud/integration_openai/pull/311)

### Changed

- Use structured output in the translation provider to limit extraneous text @kyteinsky [#326](https://github.com/nextcloud/integration_openai/pull/326)

### Fixed

- Handle empty userIds in quota accounting @kyteinsky [#319](https://github.com/nextcloud/integration_openai/pull/319)

## 4.1.1 - 2026-01-14

### Fixed
- fix: use absolute URL in notifications @joshtrichards [#317](https://github.com/nextcloud/integration_openai/pull/317)
- fix(TextToImageProvider): Use userFacingErrorMessages @marcelklehr [#315](https://github.com/nextcloud/integration_openai/pull/315)
- fix(OldProcessing): use IAppConfig to get the selected model @kyteinsky [#318](https://github.com/nextcloud/integration_openai/pull/318)


## 4.1.0 - 2025-12-23

### Added
- add support for chat memories @marcelklehr [#310](https://github.com/nextcloud/integration_openai/pull/310)

### Fixed
- fix release archive: include Php dependencies, exclude stylelint and vite config files @julien-nc [#312](https://github.com/nextcloud/integration_openai/pull/312)

## 4.0.0 - 2025-12-09

### Added
- add watermark and metadata to generated images and audio @marcelklehr [#295](https://github.com/nextcloud/integration_openai/pull/295)
- adjust audio input chat format @julien-nc [#303](https://github.com/nextcloud/integration_openai/pull/303)
- detect audio input format when sending it as a message to the chat completion endpoint, support wav and mp3 @julien-nc [#306](https://github.com/nextcloud/integration_openai/pull/306)

### Changed
- drop support for NC < 33 @marcelklehr [#295](https://github.com/nextcloud/integration_openai/pull/295)


## 3.9.1 – 2025-11-05

### Fixed
- catch errors in model fetch during migration @kyteinsky [#289](https://github.com/nextcloud/integration_openai/pull/289)


## 3.9.0 – 2025-11-04

### Fixed
- correct app version release


## 3.8.0 – 2025-11-04

### Added
- add chunking to ChangeTone, ContextWrite, ProofreadProvider, ReformulateProvider, TopicsProvider, and TranslateProvider @lukasdotcom [#240](https://github.com/nextcloud/integration_openai/pull/240)
- implement auto detection for task types and toggle for analyze image @lukasdotcom [#252](https://github.com/nextcloud/integration_openai/pull/252)
- add notification when quota exceeded @lukasdotcom [#254](https://github.com/nextcloud/integration_openai/pull/254)
- add monthly quota periods @lukasdotcom [#256](https://github.com/nextcloud/integration_openai/pull/256)
- implement quota rules @lukasdotcom [#255](https://github.com/nextcloud/integration_openai/pull/255)
- add quota report download @lukasdotcom [#257](https://github.com/nextcloud/integration_openai/pull/257)
- add config for how long to store usage @lukasdotcom [#257](https://github.com/nextcloud/integration_openai/pull/257)
- add AI app category @janepie [#275](https://github.com/nextcloud/integration_openai/pull/275)
- specify language for AudioToText @lukasdotcom [#266](https://github.com/nextcloud/integration_openai/pull/266)
- add user preference to set default language for AudioToText @lukasdotcom [#266](https://github.com/nextcloud/integration_openai/pull/266)

### Changed
- update context needed for the quota reset day @lukasdotcom [#265](https://github.com/nextcloud/integration_openai/pull/265)
- add openai api info in the readme @kyteinsky [#268](https://github.com/nextcloud/integration_openai/pull/268)
- lazy load most app config values, store them as lazy too @julien-nc [#246](https://github.com/nextcloud/integration_openai/pull/246)
- bump max NC version to 33
- hint about the v1 in the custom service url info text @kyteinsky [#279](https://github.com/nextcloud/integration_openai/pull/279)
- change default completion model to 4.1 mini @lukasdotcom [#271](https://github.com/nextcloud/integration_openai/pull/271)

### Fixed
- use admin specified max tokens as default for all tasks it makes sense for @lukasdotcom [#239](https://github.com/nextcloud/integration_openai/pull/239)
- admin settings style and quota fixes @kyteinsky [#241](https://github.com/nextcloud/integration_openai/pull/241)
- fix default tts model selector for vue3 @lukasdotcom [#247](https://github.com/nextcloud/integration_openai/pull/247)
- fix empty argument gets encoded as [] instead of {} @lukasdotcom [#243](https://github.com/nextcloud/integration_openai/pull/243)
- fix custom unreachable service handling, store the model list in the DB @julien-nc [#277](https://github.com/nextcloud/integration_openai/pull/277)


## 3.7.1 – 2025-07-16

### Fixed

- Prefer using the old message format in the chat history: content is a string. Use the new format when there is audio @julien-nc [#237](https://github.com/nextcloud/integration_openai/pull/237)


## 3.7.0 – 2025-07-11

### Added

- Implement audio chat provider @julien-nc [#227](https://github.com/nextcloud/integration_openai/pull/227)

### Fixed

- update composer with php8.1 @kyteinsky [#233](https://github.com/nextcloud/integration_openai/pull/233)
- Invalidate all cache with a prefix instead of deleting one key that we don't use anymore @julien-nc [#234](https://github.com/nextcloud/integration_openai/pull/234)


## 3.6.0 – 2025-07-09

### Added

- Add support for text to speech @lukasdotcom [#210](https://github.com/nextcloud/integration_openai/pull/210)
- Add Analyze Image Task Type @lukasdotcom [#226](https://github.com/nextcloud/integration_openai/pull/226)

### Changed

- update README.md @edward-ly @jospoortvliet [#46](https://github.com/nextcloud/integration_openai/pull/46)
- update summary prompt @edward-ly [#214](https://github.com/nextcloud/integration_openai/pull/214)
- migrate to vue3 and @nextcloud/vue 9 @julien-nc [#220](https://github.com/nextcloud/integration_openai/pull/220)
- use outline icons instead of solid ones @kyteinsky [#228](https://github.com/nextcloud/integration_openai/pull/228)
- update personal settings basic auth @kyteinsky [#228](https://github.com/nextcloud/integration_openai/pull/228)

### Fixed

- do not add /v1 twice to the service url @kyteinsky [#207](https://github.com/nextcloud/integration_openai/pull/207)
- Fix some mistakes in phpdoc @lukasdotcom [#211](https://github.com/nextcloud/integration_openai/pull/211)
- fix English source string @edward-ly [#212](https://github.com/nextcloud/integration_openai/pull/212)
- improve 401 error message @kyteinsky [#218](https://github.com/nextcloud/integration_openai/pull/218)
- Update all workflows and fix bootstrap.php @lukasdotcom [#221](https://github.com/nextcloud/integration_openai/pull/221)
- fix content type identification @lukasdotcom [#225](https://github.com/nextcloud/integration_openai/pull/225)
- separate user and admin cache, and allow taskproc without userId @kyteinsky [#230](https://github.com/nextcloud/integration_openai/pull/230)


## 3.5.0 – 2025-03-11

### Added

- Add support for together ai @kyteinsky [#197](https://github.com/nextcloud/integration_openai/pull/197)

### Changed

- Enable use_max_completion_tokens_param by default for OpenAI, disable by default for non-OpenAI @julien-nc [#184](https://github.com/nextcloud/integration_openai/pull/184)
- Add admin setting to toggle authentication in the image retrieval request @julien-nc [#186](https://github.com/nextcloud/integration_openai/pull/186)
- Add a warning in the admin settings if the assistant app is not installed @julien-nc [#185](https://github.com/nextcloud/integration_openai/pull/185)
- Raise the default image size for compatibility @Rello [#193](https://github.com/nextcloud/integration_openai/pull/193)
- Bump max NC version to 32

### Fixed

- Prevent browser autocomplete for API key inputs and credentials @julien-nc [#187](https://github.com/nextcloud/integration_openai/pull/187)
- Do not attempt to update local quota if chat/completion response does not have usage.total_tokens set @julien-nc [#189](https://github.com/nextcloud/integration_openai/pull/189)
- Prevent error log flood when not configured @kyteinsky [#199](https://github.com/nextcloud/integration_openai/pull/199)

## 3.4.0 – 2025-01-22

### Changed

- Use max_completion_tokens instead of deprecated max_tokens @julien-nc [#176](https://github.com/nextcloud/integration_openai/pull/176)

### Added

- Add reuse compliance @AndyScherzinger [#173](https://github.com/nextcloud/integration_openai/pull/173)
- Make image generation work with base64 responses (e.g. IONOS) @julien-nc @janepie [#177](https://github.com/nextcloud/integration_openai/pull/177)
- Mention IONOS as service with an OpenAI-compatible API @julien-nc [#178](https://github.com/nextcloud/integration_openai/pull/178)
- Support chatting with o1 models @julien-nc [#179](https://github.com/nextcloud/integration_openai/pull/179)


## 3.3.0 – 2025-01-07

### Added

- Change tone task type + provider @janepie [#163](https://github.com/nextcloud/integration_openai/pull/163) [#172](https://github.com/nextcloud/integration_openai/pull/172)
- Proofread provider @janepie [#169](https://github.com/nextcloud/integration_openai/pull/169)
- ChatWithTools provider @julien-nc [#167](https://github.com/nextcloud/integration_openai/pull/167)

### Changed

- Add ability to summarize by chunks @edward-ly [#157](https://github.com/nextcloud/integration_openai/pull/157)

### Fixed

- Fix headline prompt @janepie [#162](https://github.com/nextcloud/integration_openai/pull/162)
- Grammar mistake @rakekniven [#165](https://github.com/nextcloud/integration_openai/pull/165)

## 3.2.0 – 2024-11-18

### Added

- Admin setting to choose transcription model @julien-nc [#154](https://github.com/nextcloud/integration_openai/pull/154)
- Admin setting to set the default image size @julien-nc [#135](https://github.com/nextcloud/integration_openai/pull/135)
- Add ability to customize API root path in service URL @edward-ly [#143](https://github.com/nextcloud/integration_openai/pull/143)

### Changed

- Encrypt api keys and basic passwords for app config and user settings @julien-nc [#136](https://github.com/nextcloud/integration_openai/pull/136) [#139](https://github.com/nextcloud/integration_openai/pull/139)
- Remove old providers since old APIs can use TaskProcessing providers @julien-nc [#140](https://github.com/nextcloud/integration_openai/pull/140)
- Cache the models for 30min in distributed cache @julien-nc [#152](https://github.com/nextcloud/integration_openai/pull/152)

### Fixed

- Missuse of user ID in most providers @julien-nc [#153](https://github.com/nextcloud/integration_openai/pull/153)
- Use api key in image retrieval request @julien-nc [#147](https://github.com/nextcloud/integration_openai/pull/147)

## 3.1.2 – 2024-09-28

### Changed

- Simple cache for model list to speed up provider loading @julien-nc [#133](https://github.com/nextcloud/integration_openai/pull/133)
- Change default image generation size @kyteinsky [#124](https://github.com/nextcloud/integration_openai/pull/124)

## 3.1.1 – 2024-09-26

### Added

- Ability to choose model in the assistant for most providers @julien-nc [#130](https://github.com/nextcloud/integration_openai/pull/130)

### Changed

- Switch from webpack to vite @julien-nc [#131](https://github.com/nextcloud/integration_openai/pull/131)

### Fixed

- Fix admin settings not saving the models in some cases @julien-nc [#130](https://github.com/nextcloud/integration_openai/pull/130)

## 3.1.0 – 2024-09-05

### Added

- admin-settings: add image model selection dropdown @kyteinsky [#122](https://github.com/nextcloud/integration_openai/pull/122)

### Changed

- bump max NC version to 31, update composer dev dependencies @julien-nc
- Bring back textProcessing, translation, STT, and image generation providers @julien-nc [#120](https://github.com/nextcloud/integration_openai/pull/120)
- Avoid ambiguity between old and new providers @julien-nc [#121](https://github.com/nextcloud/integration_openai/pull/121)

### Fixed

- fix(TextToText,Translate): Don't throw in getOptionalInputShapeEnumValues @marcelklehr [#115](https://github.com/nextcloud/integration_openai/pull/115)
- fix(providers): do no implement useless 'getOptionalOutputShapeDefaults' method, use the size param in text2image provider @julien-nc [#119](https://github.com/nextcloud/integration_openai/pull/119)

## 3.0.1 – 2024-07-26

### Added

- Translation provider for the task processing API @julien-nc [#111](https://github.com/nextcloud/integration_openai/pull/111)

### Changed

- Adjust providers to changes in server (enum values and defaults) @julien-nc [#111](https://github.com/nextcloud/integration_openai/pull/111)
- Drop the old translation provider @julien-nc [#111](https://github.com/nextcloud/integration_openai/pull/111)

## 3.0.0 – 2024-07-17

### Added

- Create all task processing providers that were text processing ones + image generation + audio transcription @julien-nc [#102](https://github.com/nextcloud/integration_openai/pull/102)
- ContextWrite provider
- Chat provider
- Add password confirmation when setting sensitive values in user and admin settings

### Changed

- Drop old image generation, audio transcription and text processing providers
- Use nc/vue 8.14.0

### Fixed

- Fix topics provider output: make it comma separated

## 2.0.3 – 2024-06-21

### Fixed
- incorrect numeric settings handling on the server side @julien-nc

### Changed
- move personal and admin settings to AI sections @julien-nc
- update npm pkgs @julien-nc


## 2.0.1 – 2024-05-06

### Added

- support MistralAI API @julien-nc

### Changed

- only add user param in chat completion request if using openAI @julien-nc
- allow empty string for extra llm params @julien-nc

### Fixed

- safely drop indexes in last migration step @julien-nc [#95](https://github.com/nextcloud/integration_openai/issues/95)
- fix mistake when getting extra model params @julien-nc [#94](https://github.com/nextcloud/integration_openai/issues/94)

## 2.0.0 – 2024-04-18

### Added

- allow admins to set extra params for completion requests @julien-nc [#86](https://github.com/nextcloud/integration_openai/pull/86)
- new "service name" admin setting @julien-nc [#87](https://github.com/nextcloud/integration_openai/pull/87)
- mention compatibility with Plusserver in README and app description @julien-nc [#87](https://github.com/nextcloud/integration_openai/pull/87)
- make it possible to toggle image, text and stt providers in admin setting @julien-nc [#87](https://github.com/nextcloud/integration_openai/pull/87)

### Changed

- support NC 30
- remove smart pickers that are now provided by the assistant app @julien-nc [#85](https://github.com/nextcloud/integration_openai/pull/85)
- use nextcloud/vue components in admin settings @julien-nc [#87](https://github.com/nextcloud/integration_openai/pull/87)

### Fixed

- use basic auth to get generated images if necessary @julien-nc [#84](https://github.com/nextcloud/integration_openai/pull/84)

## 1.2.1 – 2024-03-13

### Changed

- chore: update workflows from templates @skjnldsv
- Update node, npm, nextcloud/vue @MB-Finski
- Update all node deps @kyteinsky

### Fixed

- Remove unnecessary spacing in admin settings @st3iny
- Fix psalm errors @MB-Finski
- Trim suffixed / in base url @kyteinsky

### Added

- Use composer bin for dev dependencies @MB-Finski
- Add psalm and lint workflows, cs:fix @MB-Finski
- Add psalm config @MB-Finski


## 1.2.0 – 2023-12-22

### Fixed

- Support new OCP API with expected runtime for text processing providers @julien-nc
- Calculate expected provider runtimes according to past observed runtimes @MB-Finski
- Specify allow_local_remote_servers in the request options so that it's no longer needed in config.php @jkellerer
- Improve unit test cleanup @MB-Finski

### Added

- Implement a text-to-image provider @julien-nc

## 1.1.5 – 2023-12-07

### Fixed

- Fix image generation with LocalAI (with LocalAi 2.0 the endpoint is no longer fully OpenAI compatible) @MB-Finski
- Fix translation provider @MB-Finski
- Respect LocalAI endpoint setting with providers @MB-Finski
- Hide unsupported settings from image generation dialog with LocalAI @MB-Finski

### Added

- Add php-unit tests for providers @MB-Finski

## 1.1.4 – 2023-12-05

### Fixed

- An attempt at fixing info.xml parsing in the app store

## 1.1.3 – 2023-12-05

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
