OC.L10N.register(
    "integration_openai",
    {
    "Bad HTTP method" : "دالة HTTP  غير صحيحة",
    "Bad credentials" : "معلومات تسجيل الدخول غير صحيحة",
    "Connected accounts" : "حسابات مترابطة",
    "This app includes 3 custom smart pickers for Nextcloud:\n* ChatGPT answers\n* DALL·E 2 images\n* Whisper dictation\n\nIt also implements\n\n* a Translation provider (using ChatGPT)\n* a SpeechToText provider (using Whisper)\n\nInstead of connecting to the OpenAI API for these, you can also connect to a self-hosted LocalAI instance.\n\n## Ethical AI Rating\n### Rating for ChatGPT via OpenAI API: 🔴\n\nNegative:\n* the software for training and inference of this model is proprietary, limiting running it locally or training by yourself\n* the trained model is not freely available, so the model can not be run on-premises\n* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n\n### Rating for DALL·E via OpenAI API: 🔴\n\nNegative:\n* the software for training and inferencing of this model is proprietary, limiting running it locally or training by yourself\n* the trained model is not freely available, so the model can not be ran on-premises\n* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n\n### Rating for Whisper via OpenAI API: 🟡\n\nPositive:\n* the software for training and inferencing of this model is open source\n* the trained model is freely available, and thus can be ran on-premises\n\nNegative:\n* the training data is not freely available, limiting the ability of external parties to check and correct for bias or optimise the model’s performance and CO2 usage.\n\n\nLearn more about the Nextcloud Ethical AI Rating [in our blog](https://nextcloud.com/blog/nextcloud-ethical-ai-rating/)." : "ييتضمن هذا التطبيق 3 أدوات انتقاء ذكية مخصصة لنكست كلود:\n* إجابات شات جي بي تي\n* أداة صور DALL·E 2 \n* استتكتاب ويسبر\n\nكما أنه يحتوي أيضا أدوات\n\n* مزود ترجمة (باستخدام شات جي بي تي)\n* مزود سبيتش تو تكست (باستخدام ويسبر)\n\nوبدلاً من الاتصال بواجهة برمجة التطبيقات لأوبن ايه أى لذلك، يمكنك أيضًا الاتصال بقاعدة بيانات لوكال ايه ىي ذاتية الاستضافة\n\n## تقييم AI الوصفي\n### تقييم شات جي بي تي من خلال واجهة برمجة التطبيقات لأوبن ايه أى: 🔴\n\nسلبي: \n* برنامج التدريب والاستدلال لهذا النموذج مسجل الملكية، مما يحد من تشغيله محليًا أو التدريب بنفسك\n* النموذج المُدرَّب غير متاح مجانًا، وبالتالي لا يمكن للنموذج تشغيل برنامج أو بريمسيز\n* لا تتوفر بيانات التدريب مجانًا، مما يحد من قدرة الأطراف الخارجية على التحقق من الخطأ المنهجي وتصحيحه أو تحسين أداء النموذج واستخدام CO2.\n\n\n### تقييم أداة صور DALL·E 2 من خلال واجهة برمجة التطبيقات لأوبن ايه أى: 🔴\n\nسلبي: \n* برنامج التدريب والاستدلال لهذا النموذج مسجل الملكية، مما يحد من تشغيله محليًا أو التدريب بنفسك\n* النموذج المُدرَّب غير متاح مجانًا، وبالتالي لا يمكن للنموذج تشغيل برنامج أو بريمسيز\n* لا تتوفر بيانات التدريب مجانًا، مما يحد من قدرة الأطراف الخارجية على التحقق من الخطأ المنهجي وتصحيحه أو تحسين أداء النموذج واستخدام CO2.\n\n\n### تقييم ويسبر من خلال واجهة برمجة التطبيقات لأوبن ايه أى: 🟡\nالإيجابي: \n* البرنامج للتدريب والاستدلال لهذا النموذج مفتوح المصدر \n* النموذج المدرب متاح مجانًا، وبالتالي يمكن تشغيل برنامج أو بريمسيز \n\nالسلبي: \n* بيانات التدريب غير متاحة مجانًا، مما يحد من قدرة الأطراف الخارجية للتحقق من الخطأ المنهجي وتصحيحه أو تحسين أداء النموذج واستخدام CO2. \n\n\nتعرف على المزيد حول التقييم الوصفي لنكست كلود AI [من خلال مدونتنا] (https://nextcloud.com/blog/nextcloud-ethical-ai-rating/ ).",
    "LocalAI URL (leave empty to use openai.com)" : "عنوان محدد موقع الموارد المُوحّد \"URL\" لقاعدة بيانات لوكال أيه آي\"LocalAI\" (اتركه فارغًا لاستخدام openai.com)",
    "example:" : "مثال:",
    "This should be the address of your LocalAI instance from the point of view of your Nextcloud server. This can be a local address with a port like http://localhost:8080" : "يجب أن يكون هذا هو عنوان نموذج لوكال أيه آي الخاص بك من وجهة نظر خادم نكست كلود الخاص بك. ويمكن أن يكون هذا عنوانًا محليًا بمنفذ مثل http://localhost:8080",
    "Select which features you want to enable" : "اختر أى السمات تريد تمكينها",
    "Whisper transcription/translation with the Smart Picker" : "استتكتاب ويسبر/ ترجمة باستخدام أداة الانتقاء الذكية",
    "Image generation with the Smart Picker" : "إنشاء الصور باستخدام أداة الانتقاء الذكية",
    "Text generation with the Smart Picker" : "إنشاء نص باستخدام أداة الانتقاء الذكية",
    "Translation provider (to translate Talk messages for example)" : "مزود الترجمة (لترجمة رسائل تطبيق توك \"Talk\"على سبيل المثال)",
    "Speech-to-text provider (to transcribe Talk recordings for example)" : "مزود تحويل الكلام إلى نص (لاستكتاب تسجيلات تطبيق توك \"Talk\"على سبيل المثال)",
    "Your administrator defined a custom service address" : "حدد المسؤول الخاص بك عنوان خدمة مخصص",
    "Preview" : "مُعاينة",
    "Advanced options" : "الخيارات المتقدمة",
    "Send" : "إرسال",
    "Unknown error" : "خطأ غير معروف",
    "Translate" : "ترجم"
},
"nplurals=6; plural=n==0 ? 0 : n==1 ? 1 : n==2 ? 2 : n%100>=3 && n%100<=10 ? 3 : n%100>=11 && n%100<=99 ? 4 : 5;");
