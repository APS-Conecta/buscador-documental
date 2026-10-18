# SPDX-License-Identifier: AGPL-3.0-or-later
"""Spanish APS topics for the S4 search evaluation. Each topic has four documents that say the same thing in different words
(full terms, acronyms, two paraphrases that avoid the key terms) and four queries: lexical (full terms), acronym, paraphrase
(words that appear in none of the documents) and accent-free. All documents of a topic are relevant to all its queries.
Synthetic text written for this evaluation; no real patient or clinic data."""

TOPICS = [
    {
        "id": "diabetes",
        "docs": [
            "Control de pacientes con diabetes mellitus tipo 2: se solicita hemoglobina glicosilada cada tres meses y glicemia en ayunas, y se ajusta la dosis de metformina según los resultados del examen.",
            "El programa de salud cardiovascular (PSCV) registra a las personas con DM2 y exige HbA1c trimestral, evaluación del pie diabético y fondo de ojo anual en el CESFAM.",
            "Seguimiento de la persona que vive con azúcar alta en la sangre: análisis periódicos del nivel de glucosa, revisión de los pies con monofilamento y ajuste del tratamiento oral por el equipo.",
            "Las usuarias y usuarios con trastorno del metabolismo de la glucosa asisten a controles regulares donde se miden sus valores de laboratorio y se refuerza la alimentación y la actividad física.",
        ],
        "queries": {
            "lexical": "hemoglobina glicosilada diabetes mellitus control trimestral",
            "acronym": "HbA1c DM2 PSCV",
            "paraphrase": "seguimiento de personas con azúcar elevada",
            "accentless": "hemoglobina glicosilada diabetes control en ayunas",
        },
    },
    {
        "id": "hipertension",
        "docs": [
            "Las personas con hipertensión arterial deben controlar su presión arterial en cada visita; si las cifras superan 140/90 se confirma el diagnóstico con un perfil de presión en tres días distintos.",
            "En el PSCV el equipo registra la PA de los pacientes con HTA, indica IECA o ARA II como primera línea y cita a control al mes si el paciente no está compensado.",
            "Cuando los valores de tensión se mantienen elevados en varias tomas, el profesional confirma el cuadro, entrega medicamentos para bajar la tensión y explica la reducción de sal en la dieta.",
            "El tamizaje de tensión alta se hace en todo adulto que consulta; quienes presentan lecturas persistentemente altas ingresan a un programa de seguimiento con controles mensuales hasta lograr la meta.",
        ],
        "queries": {
            "lexical": "hipertensión arterial presión arterial control",
            "acronym": "HTA PA PSCV IECA",
            "paraphrase": "tensión alta tamizaje y medicamentos para bajarla",
            "accentless": "hipertension arterial presion control mensual",
        },
    },
    {
        "id": "vacunacion_escolar",
        "docs": [
            "La campaña de vacunación escolar contra el virus papiloma humano se realiza en cuarto básico con la autorización de los apoderados y se registra en el Registro Nacional de Inmunizaciones.",
            "El PNI coordina con los colegios la vacuna VPH y la dTp acelular de 1° y 8° básico; el vacunatorio del CESFAM carga cada dosis en el RNI.",
            "Equipos de enfermería visitan las escuelas para inmunizar a niñas y niños en edad escolar, piden el consentimiento por escrito a las familias y anotan cada dosis en el sistema nacional.",
            "Para prevenir el cáncer de cuello uterino futuro, los escolares de nivel básico reciben su inyección protectora en el establecimiento educacional durante el primer semestre.",
        ],
        "queries": {
            "lexical": "vacunación escolar virus papiloma humano cuarto básico",
            "acronym": "VPH PNI RNI dTp",
            "paraphrase": "inmunizar niños en las escuelas contra el cáncer de cuello uterino",
            "accentless": "vacunacion escolar virus papiloma humano",
        },
    },
    {
        "id": "cadena_frio",
        "docs": [
            "La cadena de frío de las vacunas debe mantenerse entre 2 y 8 grados; el refrigerador del vacunatorio tiene termómetro de máxima y mínima y se registra la temperatura dos veces al día.",
            "Ante un corte de energía el encargado del PNI traslada las vacunas a la caja térmica, avisa al servicio de salud y reporta el incidente como posible pérdida de cadena de frío.",
            "Los productos biológicos se conservan en un equipo refrigerado con control de temperatura; si el registro muestra valores fuera de rango se aparta el lote y se consulta antes de usarlo.",
            "Se revisan a diario las lecturas del frigorífico donde se guardan las dosis y se deja constancia firmada; cualquier desviación se informa de inmediato a la jefatura.",
        ],
        "queries": {
            "lexical": "cadena de frío vacunas temperatura refrigerador",
            "acronym": "PNI cadena de frío",
            "paraphrase": "conservación de las dosis en el frigorífico con lectura diaria",
            "accentless": "cadena de frio vacunas temperatura corte de energia",
        },
    },
    {
        "id": "empam",
        "docs": [
            "El examen de medicina preventiva del adulto mayor evalúa la funcionalidad con la escala EFAM, el equilibrio con la estación unipodal y el estado de ánimo con el cuestionario de Yesavage.",
            "El EMPAM clasifica a las personas de 65 años o más como autovalentes o en riesgo de dependencia y deriva a quienes lo necesitan al programa Más Adultos Mayores Autovalentes.",
            "A las personas mayores se les mide cuánto pueden hacer solas en su vida diaria, cómo caminan y si tienen tristeza, y según el resultado se organiza un plan de apoyo en el centro de salud.",
            "La evaluación anual de quienes superan los sesenta y cinco años revisa memoria, marcha, equilibrio y autonomía para detectar a tiempo la pérdida de capacidades.",
        ],
        "queries": {
            "lexical": "examen de medicina preventiva del adulto mayor funcionalidad",
            "acronym": "EMPAM EFAM",
            "paraphrase": "evaluación anual de capacidades y autonomía en personas de edad avanzada",
            "accentless": "examen medicina preventiva adulto mayor funcionalidad autovalente",
        },
    },
    {
        "id": "ecicep",
        "docs": [
            "La estrategia de cuidado integral centrado en las personas se aplica a pacientes con multimorbilidad crónica; se estratifica el riesgo y se elabora un plan de cuidado integral consensuado con el usuario.",
            "En ECICEP los usuarios G2 y G3 tienen gestor de casos, ingreso integral y controles de seguimiento; el PCI se revisa en la reunión del equipo de cabecera.",
            "Para quienes conviven con varias enfermedades crónicas al mismo tiempo se acuerda con la persona un plan de acciones, y un profesional de referencia vigila que se cumpla.",
            "El modelo atiende a la persona completa y no a cada enfermedad por separado: se evalúa su complejidad, se fijan metas conjuntas y se programan visitas coordinadas.",
        ],
        "queries": {
            "lexical": "cuidado integral centrado en las personas multimorbilidad crónica",
            "acronym": "ECICEP PCI G3",
            "paraphrase": "atención coordinada para quienes tienen varias enfermedades a la vez",
            "accentless": "cuidado integral multimorbilidad cronica plan de cuidado",
        },
    },
    {
        "id": "control_prenatal",
        "docs": [
            "El control prenatal lo realiza la matrona: se mide la altura uterina, se auscultan los latidos cardiofetales y se pide el examen de VIH, sífilis y hepatitis B a todas las gestantes.",
            "En Chile Crece Contigo (ChCC) la gestante recibe la escala EPSA en el ingreso y a las 28 semanas, y se deriva a ARO si presenta preeclampsia o RCIU.",
            "Las mujeres embarazadas asisten a revisiones periódicas donde se vigila el crecimiento del bebé, la presión de la madre y se entregan indicaciones de alimentación y señales de alarma.",
            "Durante la espera de un hijo o hija se programan visitas con la profesional de partos para controlar la salud de ambos, hacer exámenes de laboratorio y preparar el nacimiento.",
        ],
        "queries": {
            "lexical": "control prenatal matrona altura uterina latidos cardiofetales",
            "acronym": "ChCC EPSA ARO RCIU",
            "paraphrase": "revisiones de la mujer embarazada y el crecimiento del bebé",
            "accentless": "control prenatal gestante matrona sifilis hepatitis",
        },
    },
    {
        "id": "nino_sano",
        "docs": [
            "El control de salud infantil mide peso, talla y perímetro cefálico y aplica la escala de evaluación del desarrollo psicomotor a los ocho y dieciocho meses.",
            "Con el EEDP y el TEPSI se detectan rezago y retraso del DSM; los niños con rezago pasan a la sala de estimulación del ChCC.",
            "Las y los lactantes asisten a visitas regulares donde se comprueba cómo crecen, qué habilidades han adquirido para su edad y si necesitan apoyo en el juego y el lenguaje.",
            "Se vigila que los menores de seis años avancen en su desarrollo de movimiento, habla y relación con otros, y se orienta a las familias sobre estimulación en el hogar.",
        ],
        "queries": {
            "lexical": "control de salud infantil desarrollo psicomotor perímetro cefálico",
            "acronym": "EEDP TEPSI DSM",
            "paraphrase": "seguimiento del crecimiento y las habilidades de los lactantes",
            "accentless": "control salud infantil desarrollo psicomotor rezago",
        },
    },
    {
        "id": "lactancia",
        "docs": [
            "Se promueve la lactancia materna exclusiva hasta el sexto mes de vida y se ofrece una clínica de lactancia para resolver dificultades de agarre o dolor al amamantar.",
            "El indicador de LME al sexto mes se informa en el REM y se incentiva con talleres de crianza del ChCC y apoyo de la matrona.",
            "Las madres que dan pecho a sus hijos pequeños reciben orientación para que el bebé se alimente solo de leche humana durante la primera mitad del año.",
            "Cuando una mujer tiene problemas para alimentar a su recién nacido con su propia leche, puede pedir hora de consejería con una profesional que revisa la técnica.",
        ],
        "queries": {
            "lexical": "lactancia materna exclusiva sexto mes clínica de lactancia",
            "acronym": "LME REM ChCC",
            "paraphrase": "alimentar al bebé solo con leche de su madre los primeros meses",
            "accentless": "lactancia materna exclusiva sexto mes consejeria",
        },
    },
    {
        "id": "depresion",
        "docs": [
            "La depresión en personas de quince años y más está incluida en las garantías explícitas en salud; el equipo aplica el cuestionario PHQ-9 y ofrece psicoterapia y, si corresponde, antidepresivos.",
            "En el programa de salud mental del CESFAM los casos de depresión GES se evalúan con PHQ-9 y se derivan al COSAM cuando hay riesgo suicida.",
            "Quien se siente triste casi todos los días, sin interés en nada y con cambios en el sueño es evaluado por psicología, que propone terapia y seguimiento cercano.",
            "Se hace un tamizaje del ánimo a los adultos que consultan por cansancio o insomnio, y si el puntaje es alto se agenda atención con salud mental dentro del plazo garantizado.",
        ],
        "queries": {
            "lexical": "depresión garantías explícitas en salud tamizaje salud mental",
            "acronym": "GES PHQ-9 COSAM",
            "paraphrase": "tristeza persistente falta de interés y problemas de sueño",
            "accentless": "depresion garantias explicitas salud mental psicoterapia",
        },
    },
    {
        "id": "pap",
        "docs": [
            "El Papanicolaou es el examen de tamizaje de cáncer cervicouterino para mujeres de 25 a 64 años, que se repite cada tres años si el resultado es normal.",
            "Un PAP con LIEAG o ASC-H se deriva a la UPC para colposcopía; el CACU está en las GES y debe tener atención oportuna.",
            "La toma de muestra del cuello del útero se realiza con espátula y cepillo en la sala de la matrona y se envía a laboratorio para buscar células anormales.",
            "Para prevenir el cáncer ginecológico, las mujeres en edad media acuden periódicamente a que se les tome una muestra y se les explique el resultado.",
        ],
        "queries": {
            "lexical": "Papanicolaou tamizaje cáncer cervicouterino mujeres",
            "acronym": "PAP CACU UPC LIEAG",
            "paraphrase": "muestra del cuello del útero para detectar células anormales",
            "accentless": "papanicolaou tamizaje cancer cervicouterino colposcopia",
        },
    },
    {
        "id": "tbc",
        "docs": [
            "Todo sintomático respiratorio con tos y expectoración por más de dos semanas debe tener una baciloscopía; si es positiva se notifica y se inicia el tratamiento acortado estrictamente supervisado.",
            "El PROCET indica TAES a los casos de TBC pulmonar y estudia a los contactos con PPD o IGRA para detectar infección latente.",
            "A la persona con tos prolongada se le pide analizar su flema en el laboratorio, y si resulta enferma recibe sus medicamentos todos los días bajo la mirada de un funcionario.",
            "Se busca activamente a quienes conviven con un paciente contagioso para hacerles pruebas y ofrecerles medicación preventiva si corresponde.",
        ],
        "queries": {
            "lexical": "tuberculosis baciloscopía sintomático respiratorio tratamiento supervisado",
            "acronym": "TBC TAES PROCET PPD",
            "paraphrase": "tos prolongada análisis de flema y medicamentos diarios supervisados",
            "accentless": "tuberculosis baciloscopia sintomatico respiratorio",
        },
    },
    {
        "id": "rem",
        "docs": [
            "El resumen estadístico mensual debe cerrarse los primeros días de cada mes; la serie A contiene las prestaciones realizadas y la serie P la población bajo control.",
            "El encargado de estadística revisa el REM A y el REM P antes de enviarlo al DEIS y corrige las diferencias con el RCE.",
            "Cada mes se consolida la producción del establecimiento en un informe oficial que el Ministerio utiliza para medir la actividad y asignar recursos.",
            "Los datos de atenciones del mes se reportan en planillas normadas; los errores de registro se corrigen antes de la fecha de cierre para no afectar la cuenta anual.",
        ],
        "queries": {
            "lexical": "resumen estadístico mensual prestaciones población bajo control",
            "acronym": "REM DEIS RCE",
            "paraphrase": "informe oficial mensual de la producción del establecimiento",
            "accentless": "resumen estadistico mensual serie A cierre",
        },
    },
    {
        "id": "licencia",
        "docs": [
            "La licencia médica electrónica se emite desde el sistema del Ministerio y el trabajador la tramita ante su institución previsional; el médico debe indicar el diagnóstico y los días de reposo.",
            "La LME se envía a la COMPIN y a la ISAPRE o FONASA, y el empleador tiene tres días para reclamar o tramitar el subsidio.",
            "Cuando un trabajador no puede asistir por enfermedad, el profesional autoriza el descanso por un número de días y el documento se registra directamente en línea.",
            "El permiso por enfermedad se presenta al empleador a través de una plataforma digital, y la comisión revisa si corresponde pagar el subsidio por incapacidad.",
        ],
        "queries": {
            "lexical": "licencia médica electrónica reposo subsidio",
            "acronym": "LME COMPIN FONASA ISAPRE",
            "paraphrase": "permiso por enfermedad tramitado en línea por el empleador",
            "accentless": "licencia medica electronica reposo subsidio",
        },
    },
    {
        "id": "oirs",
        "docs": [
            "La oficina de información, reclamos y sugerencias recibe los reclamos de los usuarios y debe responder por escrito dentro del plazo legal de quince días hábiles.",
            "OIRS registra felicitaciones, sugerencias y reclamos; los reclamos se derivan a la dirección del CESFAM y se informa a la Superintendencia de Salud si corresponde.",
            "Si una persona queda disconforme con la atención recibida puede dejar su queja formal en un buzón o presentarla en el mesón, y recibirá una respuesta con las medidas adoptadas.",
            "Los comentarios de la comunidad, sean críticas o agradecimientos, se anotan en un libro y se analizan cada mes para mejorar el trato.",
        ],
        "queries": {
            "lexical": "oficina de información reclamos y sugerencias plazo de respuesta",
            "acronym": "OIRS",
            "paraphrase": "queja formal por mala atención y respuesta escrita",
            "accentless": "oficina de informacion reclamos sugerencias respuesta",
        },
    },
    {
        "id": "lista_espera",
        "docs": [
            "La lista de espera de consulta nueva de especialidad se gestiona con el repositorio nacional; cada solicitud de interconsulta debe tener prioridad y fecha de ingreso.",
            "El SOME ingresa la SIC al RNLE y verifica periódicamente la vigencia de los casos de LE NO GES y la contraderivación desde el CDT.",
            "Los pacientes que esperan ser vistos por un especialista deben estar registrados con su prioridad clínica, y se les llama para confirmar si aún necesitan la atención.",
            "Se depura el listado de personas pendientes de hora con el hospital, retirando a quienes fallecieron, ya fueron atendidos o no quieren continuar.",
        ],
        "queries": {
            "lexical": "lista de espera interconsulta consulta nueva de especialidad",
            "acronym": "LE NO GES RNLE SIC CDT",
            "paraphrase": "personas pendientes de hora con el especialista del hospital",
            "accentless": "lista de espera interconsulta especialidad prioridad",
        },
    },
    {
        "id": "farmacia",
        "docs": [
            "La receta crónica permite despachar los medicamentos en entregas mensuales; el químico farmacéutico verifica el stock y deja constancia de los faltantes en el sistema de la farmacia.",
            "En el FOFAR el despacho de fármacos del arsenal para el PSCV se registra por RUT, y los pendientes se entregan cuando llega la reposición.",
            "Las personas que toman remedios de forma permanente retiran lo que necesitan cada mes en el mesón de entrega, y si algo falta se anota para avisarles después.",
            "Se controla que haya existencias suficientes de los productos más usados y se pide reposición a la central de abastecimiento antes de que se agoten.",
        ],
        "queries": {
            "lexical": "receta crónica despacho de medicamentos químico farmacéutico faltantes",
            "acronym": "FOFAR PSCV RUT CENABAST",
            "paraphrase": "retiro mensual de remedios permanentes y aviso si faltan",
            "accentless": "receta cronica despacho medicamentos quimico farmaceutico",
        },
    },
    {
        "id": "pie_diabetico",
        "docs": [
            "La curación avanzada del pie diabético usa apósitos de hidrogel o alginato, desbridamiento y control del dolor; la úlcera se mide y se fotografía en cada curación.",
            "Los pacientes con UPD se derivan a podología clínica del PSCV y se evalúa el ITB para descartar enfermedad arterial.",
            "Las heridas de los pies de las personas con diabetes se limpian, se retira el tejido muerto y se cubren con materiales especiales que mantienen la humedad adecuada.",
            "Para evitar amputaciones se examina a diario la piel de las extremidades inferiores y se enseña al paciente a cuidar sus pies y usar calzado adecuado.",
        ],
        "queries": {
            "lexical": "curación avanzada pie diabético úlcera apósitos",
            "acronym": "UPD ITB PSCV",
            "paraphrase": "heridas de los pies en personas con diabetes y prevención de amputaciones",
            "accentless": "curacion avanzada pie diabetico ulcera aposito",
        },
    },
    {
        "id": "reas",
        "docs": [
            "El manejo de residuos de establecimientos de atención de salud exige segregar los cortopunzantes en cajas rígidas, los residuos infecciosos en bolsa roja y los asimilables a domiciliarios en bolsa negra.",
            "El encargado de REAS capacita al personal, lleva el registro de retiros y verifica que la empresa autorizada transporte los residuos peligrosos del CESFAM.",
            "Las agujas usadas se desechan en un recipiente resistente que se cierra y se retira cuando está lleno; nunca se vuelven a tapar ni se mezclan con la basura común.",
            "La eliminación de desechos clínicos sigue una norma que define colores de bolsas, tiempos de almacenamiento y empresas habilitadas para su traslado y disposición final.",
        ],
        "queries": {
            "lexical": "residuos de establecimientos de atención de salud cortopunzantes bolsa roja",
            "acronym": "REAS CESFAM",
            "paraphrase": "desechar agujas usadas y basura clínica según la norma",
            "accentless": "residuos establecimientos atencion de salud cortopunzantes",
        },
    },
    {
        "id": "acreditacion",
        "docs": [
            "El proceso de acreditación evalúa estándares de calidad y seguridad del paciente ante la Superintendencia de Salud; los eventos adversos y centinela se notifican y se analizan.",
            "El encargado de calidad coordina la acreditación, revisa los EA y los EC, y mantiene los POE actualizados para la visita de la entidad acreditadora.",
            "Para demostrar que el centro atiende de forma segura se revisan sus procedimientos, se miden indicadores y se investigan los incidentes con daño al paciente.",
            "Cada cierto tiempo una entidad externa visita el establecimiento y comprueba que se cumplen las normas de seguridad, higiene y trato digno.",
        ],
        "queries": {
            "lexical": "acreditación calidad seguridad del paciente eventos adversos",
            "acronym": "EA EC POE",
            "paraphrase": "visita externa para comprobar que se atiende de forma segura",
            "accentless": "acreditacion calidad seguridad paciente evento centinela",
        },
    },
    {
        "id": "vdi",
        "docs": [
            "La visita domiciliaria integral la realiza el equipo de sector a familias con riesgo biopsicosocial; se elabora un genograma y un ecomapa y se acuerdan acciones con la familia.",
            "La VDI se registra en el REM A y se programa en la reunión de sector; el rescate de inasistentes también se hace por visita domiciliaria.",
            "Profesionales salen del centro para conocer en la propia casa las condiciones de vida de una persona o familia y trabajar con ellos el cuidado de su salud.",
            "Cuando alguien no puede acudir a su control por dependencia o enfermedad, el equipo va hasta su hogar, evalúa su entorno y entrega cuidados y orientación.",
        ],
        "queries": {
            "lexical": "visita domiciliaria integral genograma ecomapa familia",
            "acronym": "VDI REM",
            "paraphrase": "el equipo va al hogar de quien no puede acudir al centro",
            "accentless": "visita domiciliaria integral genograma familia riesgo",
        },
    },
    {
        "id": "pnac",
        "docs": [
            "El programa nacional de alimentación complementaria entrega leche purita fortificada a lactantes y gestantes, y el PACAM entrega crema años dorados a personas mayores.",
            "La bodega PNAC/PACAM registra las entregas por RUT y la nutricionista controla la entrega en cada control de salud.",
            "Los niños con riesgo nutricional y las personas mayores reciben alimentos fortificados de forma gratuita para mejorar su estado de nutrición.",
            "Se distribuyen productos lácteos y cereales especiales a ciertos grupos de la población, con control periódico del peso y la talla por parte de la profesional.",
        ],
        "queries": {
            "lexical": "programa nacional de alimentación complementaria leche purita personas mayores",
            "acronym": "PNAC PACAM",
            "paraphrase": "alimentos fortificados gratuitos para niños con riesgo nutricional",
            "accentless": "programa nacional alimentacion complementaria leche purita",
        },
    },
    {
        "id": "odontologia",
        "docs": [
            "La garantía de salud oral integral a los seis años incluye examen, aplicación de sellantes y barniz de flúor, y la atención se agenda en el box dental del CESFAM.",
            "El GES de 6 años y el programa CERO usan la pauta de riesgo cariogénico y el índice COPD/ceo para planificar controles odontológicos.",
            "A las niñas y niños que comienzan la escuela se les protegen las muelas definitivas con una resina y se revisa la salud de sus dientes sin costo para la familia.",
            "El dentista realiza actividades de prevención en párvulos, enseña el cepillado y aplica productos para evitar las caries.",
        ],
        "queries": {
            "lexical": "salud oral integral seis años sellantes barniz de flúor",
            "acronym": "GES CERO COPD ceo",
            "paraphrase": "proteger las muelas de los escolares para evitar caries",
            "accentless": "salud oral integral seis anos sellantes barniz fluor",
        },
    },
    {
        "id": "sapu",
        "docs": [
            "En el servicio de atención primaria de urgencia la categorización de los pacientes sigue el índice de severidad de emergencia, de C1 riesgo vital a C5 consulta general.",
            "El SAPU aplica ESI al ingreso y deriva a la UEH los casos C1 y C2; la constatación de lesiones se hace a solicitud policial.",
            "Al llegar al servicio de urgencia, una profesional clasifica a cada persona según la gravedad de su cuadro para decidir quién se atiende primero.",
            "Las personas con riesgo inmediato para su vida pasan de inmediato a la sala de reanimación, mientras las consultas leves esperan su turno.",
        ],
        "queries": {
            "lexical": "servicio de atención primaria de urgencia categorización índice de severidad",
            "acronym": "SAPU ESI UEH C1",
            "paraphrase": "clasificar la gravedad de quienes llegan a urgencia para decidir quién pasa primero",
            "accentless": "servicio de atencion primaria de urgencia categorizacion",
        },
    },
    {
        "id": "consentimiento",
        "docs": [
            "El consentimiento informado es un documento ético-legal que el paciente firma después de recibir información clara sobre el procedimiento, sus riesgos y alternativas, según la ley de derechos y deberes.",
            "La Ley 20.584 exige consentimiento informado para procedimientos invasivos; en menores se pide además el asentimiento del niño, niña o adolescente.",
            "Antes de una intervención se explica con palabras sencillas en qué consiste, qué puede salir mal y qué otras opciones existen, y la persona decide libremente si acepta.",
            "Nadie puede ser sometido a un tratamiento sin haber comprendido lo que se le propone; la autorización queda registrada en la ficha del paciente.",
        ],
        "queries": {
            "lexical": "consentimiento informado procedimiento riesgos derechos y deberes",
            "acronym": "Ley 20.584",
            "paraphrase": "autorización libre del paciente después de entender lo que se le propone",
            "accentless": "consentimiento informado procedimiento riesgos asentimiento",
        },
    },
    {
        "id": "metas",
        "docs": [
            "Las metas sanitarias de la ley 19.813 se evalúan cada año y su cumplimiento determina el bono de estímulo al personal de atención primaria municipal.",
            "El IAAPS y las metas sanitarias se informan al servicio de salud; el corte FONASA y la población inscrita validada determinan el per cápita.",
            "El desempeño anual del equipo se mide con indicadores acordados y, si se alcanzan los porcentajes, los funcionarios reciben un incentivo económico.",
            "Los recursos que llegan al centro dependen de cuántas personas están inscritas y de los compromisos de gestión cumplidos durante el período.",
        ],
        "queries": {
            "lexical": "metas sanitarias bono de estímulo población inscrita",
            "acronym": "IAAPS FONASA COMGES",
            "paraphrase": "incentivo económico al personal por cumplir indicadores anuales",
            "accentless": "metas sanitarias bono estimulo poblacion inscrita",
        },
    },
    {
        "id": "participacion",
        "docs": [
            "El consejo de desarrollo local en salud reúne a representantes de la comunidad y del CESFAM; se realiza un diagnóstico participativo y se prioriza el uso del presupuesto participativo.",
            "El CDL sesiona mensualmente, el encargado de participación levanta actas y la cuenta pública rinde cuentas a los usuarios.",
            "Vecinas y vecinos se reúnen con el equipo del centro para opinar sobre los problemas del barrio y decidir en qué proyectos se gastan ciertos fondos.",
            "Una vez al año el establecimiento presenta a la comunidad lo que hizo y lo que gastó, y escucha propuestas para el año siguiente.",
        ],
        "queries": {
            "lexical": "consejo de desarrollo local en salud diagnóstico participativo cuenta pública",
            "acronym": "CDL CESFAM",
            "paraphrase": "vecinos opinan y deciden en qué proyectos del barrio se gastan fondos",
            "accentless": "consejo desarrollo local salud diagnostico participativo",
        },
    },
]
