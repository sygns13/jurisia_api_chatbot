create database u230756120_JURISDB_CHATB;
use u230756120_JURISDB_CHATB;

CREATE TABLE If Not Exists u230756120_JURISDB_CHATB.MainConsulta (
id bigint unsigned primary key not null auto_increment,
status tinyint DEFAULT 1 Comment 'Status del step: 1-> Iniciado, 0 -> No encontrado, 2 -> Encontrado',
step tinyint DEFAULT 0 Comment 'Paso de la consulta: 0-> Inicio, 1-> Consulta Exp, 2-> Consulta Parte, 3-> Consulta Mensaje, 4 -> done',
service char(50) DEFAULT NULL Comment 'Servicio de Consulta: telegram, whatsapp, etc.',
chatId char(100) NOT NULL Comment 'Id del Chat',
message text default null Comment 'Texto del mensaje',
regDate date Null Comment 'Fecha create',
regDatetime datetime Null Comment 'Fecha Hora create',
regTimestamp bigint Null Comment 'Epoch create',
updDate date Null Comment 'Fecha Update',
updDatetime datetime Null Comment 'Fecha Hora Update',
updTimestamp bigint Null Comment 'Epoch Update'
) ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla Principal de Consulta por el Bot';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.MainConsulta
    ADD INDEX statusIDX (status),
    ADD INDEX stepIDX (step),
    ADD INDEX serviceIDX (service),
    ADD INDEX chatIdIDX (chatId),
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX updDateIDX (updDate),
    ADD INDEX updDatetimeIDX (updDatetime),
    ADD INDEX updTimestampIDX (updTimestamp);


CREATE TABLE If Not Exists u230756120_JURISDB_CHATB.CabExpediente (
id bigint unsigned primary key not null auto_increment,
xFormato char(50) DEFAULT NULL Comment 'Formato del Expediente',
nUnico bigint unsigned DEFAULT NULL Comment 'Numero Unico del Expediente',
nIncidente bigint unsigned DEFAULT NULL Comment 'Numero de Incidente',
tipoExpediente char(100) DEFAULT NULL Comment 'Tipo de Expediente',
codEspecialidad char(20) DEFAULT NULL Comment 'Codigo de Especialidad en el SIJ',
codInstancia char(20) DEFAULT NULL Comment 'Codigo de Instancia en el SIJ',
instancia char(200) DEFAULT NULL Comment 'Nombre de Instancia en el SIJ',
organoJurisd char(200) DEFAULT NULL Comment 'Nombre de Instancia en el SIJ',
sede char(200) DEFAULT NULL Comment 'Nombre de Sede en el SIJ',
indAnulado char(20) DEFAULT NULL Comment 'Nombre de Sede en el SIJ',
indUltimo char(20) DEFAULT NULL Comment 'Nombre de Sede en el SIJ',
regDate date Null Comment 'Fecha create',
regDatetime datetime Null Comment 'Fecha Hora create',
regTimestamp bigint Null Comment 'Epoch create',
chatId char(100) NOT NULL Comment 'Id del Chat'
) ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla Principal de Consulta de Expedientes';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.CabExpediente
    ADD INDEX xFormatoIDX (xFormato),
    ADD INDEX nUnicoIDX (nUnico),
    ADD INDEX nIncidenteIDX (nIncidente),
    ADD INDEX tipoExpedienteIDX (tipoExpediente),
    ADD INDEX codInstanciaIDX (codInstancia),
    ADD INDEX codEspecialidadIDX (codEspecialidad),
    ADD INDEX indAnuladoIDX (indAnulado),
    ADD INDEX indUltimoIDX (indUltimo),
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX chatIdIDX (chatId);    

CREATE TABLE If Not Exists u230756120_JURISDB_CHATB.PartesExp ( 
    id bigint unsigned primary key not null auto_increment,
    cTipoPersona        char(10)     COMMENT 'Código del tipo de persona (ej: N para Natural, J para Jurídica).',
    xDescTipoPersona    char(255)    COMMENT 'Descripción del tipo de persona (Natural, Jurídica).',
    indTipoParte          char(20)     COMMENT 'Letra o código que identifica el tipo de parte (ej: D para Demandante).',
    xDescParte          char(255)    COMMENT 'Descripción del tipo de parte (Demandante, Demandado, etc.).',
    xApePaterno         char(255)    COMMENT 'Apellido paterno de la persona.',
    xApeMaterno         char(255)    COMMENT 'Apellido materno de la persona.',
    xNombres            char(255)    COMMENT 'Nombres de la persona o razón social.',
    xDocId              char(25)     COMMENT 'Número del documento de identidad.',
    cTipo               char(10)     COMMENT 'Código del tipo de documento de identidad.',
    xTipoDoc            char(100)    COMMENT 'Descripción del tipo de documento (DNI, RUC, etc.).',
    xAbrevi             char(20)     COMMENT 'Abreviatura del tipo de documento.',
    indActivo             CHAR(1)         COMMENT 'Flag de estado activo (ej: S para sí, N para no).',
    nUnico              BIGINT          COMMENT 'Número único del expediente al que pertenece la parte.',
    regDate date Null Comment 'Fecha create',
    regDatetime datetime Null Comment 'Fecha Hora create',
    regTimestamp bigint Null Comment 'Epoch create',
    chatId char(100) NOT NULL Comment 'Id del Chat'
) ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla de Consulta de Partes del Expediente';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.PartesExp
    ADD INDEX cTipoPersonaIDX (cTipoPersona),
    ADD INDEX indTipoParte (indTipoParte),
    ADD INDEX xDocIdIDX (xDocId),
    ADD INDEX cTipoIDX (cTipo),
    ADD INDEX indActivoIDX (indActivo),
    ADD INDEX nUnicoIDX (nUnico),
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX chatIdIDX (chatId);   



CREATE TABLE DetailsExpediente (
    id bigint unsigned primary key not null auto_increment,
    nUnico              BIGINT          COMMENT 'Número único del expediente al que pertenece la parte.',
    xFormato            VARCHAR(100)    COMMENT 'Número de formato completo del expediente.',
    xNomInstancia       VARCHAR(255)    COMMENT 'Nombre de la instancia judicial (juzgado, sala, etc.).',
    codEspecialidad       VARCHAR(20)     COMMENT 'Código de la especialidad del expediente (Civil, Penal, etc.).',
    xDescMateria        VARCHAR(255)    COMMENT 'Descripción de la materia o asunto principal del proceso.',
    fInicio             DATETIME        COMMENT 'Fecha y hora de inicio del expediente.',
    xDescEstado         VARCHAR(255)    COMMENT 'Descripción del estado actual del expediente (En trámite, Archivado, etc.).',
    codUbicacion          VARCHAR(20)     COMMENT 'Código de la ubicación física o lógica del expediente.',
    xDescUbicacion      VARCHAR(255)    COMMENT 'Descripción de la ubicación del expediente (Secretaría, Archivo, etc.).',
    usuarioJuez         VARCHAR(50)     COMMENT 'Código de usuario del juez titular asignado.',
    juez                VARCHAR(255)    COMMENT 'Nombre completo del juez titular asignado.',
    usuarioSecretario   VARCHAR(50)     COMMENT 'Código de usuario del secretario judicial asignado.',
    secretario          VARCHAR(255)    COMMENT 'Nombre completo del secretario judicial asignado.',
    tipoExpediente      VARCHAR(20)     COMMENT 'Indica si el expediente es Físico o Electrónico.',
    parte               VARCHAR(500)    COMMENT 'Nombre completo de una de las partes involucradas.',
    indTipoParte          VARCHAR(10)     COMMENT 'Letra o código que identifica el tipo de parte (Demandante, etc.).',
    xDescParte          VARCHAR(255)    COMMENT 'Descripción del rol de la parte.',
    regDate date Null Comment 'Fecha create',
    regDatetime datetime Null Comment 'Fecha Hora create',
    regTimestamp bigint Null Comment 'Epoch create',
    chatId char(100) NOT NULL Comment 'Id del Chat'
) 
ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla de Detalles del Expediente';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.DetailsExpediente
    ADD INDEX nUnicoIDX (nUnico),
    ADD INDEX xFormatoIDX (xFormato),
    ADD INDEX codEspecialidadIDX (codEspecialidad),
    ADD INDEX indTipoParte (indTipoParte),    
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX chatIdIDX (chatId);   