-- =====================================================================================
-- backup_02.sql
--
-- Habilita las opciones de consulta que faltaban en el bot de Telegram/WhatsApp:
--   info_general        -> se resuelve extendiendo DetailsExpediente (no requiere tabla nueva)
--   detalle_escritos    -> tabla EscritosExp
--   audiencias_realizadas / proximas_audiencias -> tabla AudienciasExp
--
-- Ejecutar sobre la base ya creada por backup_01.sql. El proyecto no usa migraciones
-- de Laravel: este script es la fuente de verdad del esquema.
--
-- NOTA sobre rutas FTP: las consultas del SIJ construyen URLs del tipo
-- ftp://usuario:clave@ip/ruta/archivo con las credenciales del servidor FTP institucional
-- embebidas. Esas cadenas NO se replican aquí: esta base vive en un hosting compartido
-- fuera de la red del Poder Judicial. Solo se guarda el nombre del archivo y, en
-- audiencias, el enlace público que el propio SIJ marca como URL (audiencia_video.l_url='S').
-- =====================================================================================

use u230756120_JURISDB_CHATB;


-- -------------------------------------------------------------------------------------
-- 1) DetailsExpediente: campos adicionales para "Información General del Expediente".
--
-- Materia, estado, ubicación, juez, secretario y partes ya se almacenaban; solo faltaban
-- las descripciones de sede y especialidad (antes se enviaba únicamente el código) y el
-- número de incidente. Por eso no se crea una tabla nueva para esta opción.
-- -------------------------------------------------------------------------------------
ALTER TABLE u230756120_JURISDB_CHATB.DetailsExpediente
    ADD COLUMN xDescSede         VARCHAR(255) NULL COMMENT 'Descripcion de la sede judicial (sede.x_desc_sede)'          AFTER tipoExpediente,
    ADD COLUMN xDescEspecialidad VARCHAR(255) NULL COMMENT 'Descripcion de la especialidad (especialidad.x_desc_especialidad)' AFTER xDescSede,
    ADD COLUMN nIncidente        CHAR(5)      NULL COMMENT 'Numero de incidente del expediente'                          AFTER xDescEspecialidad;


-- -------------------------------------------------------------------------------------
-- 2) EscritosExp: escritos presentados en el expediente.
--
-- El microservicio envia como maximo los 50 mas recientes por consulta; el bot muestra 10.
-- -------------------------------------------------------------------------------------
CREATE TABLE If Not Exists u230756120_JURISDB_CHATB.EscritosExp (
    id bigint unsigned primary key not null auto_increment,
    nUnico          BIGINT          COMMENT 'Numero unico del expediente.',
    xFormato        VARCHAR(100)    COMMENT 'Numero de formato completo del expediente.',
    nIncidente      CHAR(5)         COMMENT 'Numero de incidente del expediente.',
    xNomInstancia   VARCHAR(255)    COMMENT 'Nombre de la instancia judicial (juzgado).',
    especialista    VARCHAR(255)    COMMENT 'Nombre del especialista legal asignado al expediente.',
    nroEscrito      CHAR(20)        COMMENT 'Numero de escrito: secuencia de ingreso + anio (ej: 25820-2026).',
    fEscrito        DATETIME        COMMENT 'Fecha y hora de presentacion del escrito.',
    fAtencion       DATETIME NULL   COMMENT 'Fecha de atencion del escrito; NULL si sigue pendiente.',
    xResolucion     VARCHAR(150) NULL COMMENT 'Resolucion que atendio el escrito; NULL si aun no fue proveido.',
    xSumilla        VARCHAR(500) NULL COMMENT 'Sumilla del escrito (recortada a 300 caracteres en origen).',
    xNombreArchivo  VARCHAR(255) NULL COMMENT 'Nombre del archivo de la resolucion de atencion, si esta digitalizada.',
    regDate date Null Comment 'Fecha create',
    regDatetime datetime Null Comment 'Fecha Hora create',
    regTimestamp bigint Null Comment 'Epoch create',
    chatId char(100) NOT NULL Comment 'Id del Chat'
)
ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla de Escritos presentados en el Expediente';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.EscritosExp
    ADD INDEX nUnicoIDX (nUnico),
    ADD INDEX xFormatoIDX (xFormato),
    ADD INDEX nroEscritoIDX (nroEscrito),
    ADD INDEX fEscritoIDX (fEscrito),
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX chatIdIDX (chatId),
    -- El bot siempre consulta por (nUnico, chatId) y ordena por fecha: indice compuesto.
    ADD INDEX chatExpedienteIDX (chatId, nUnico, fEscrito);


-- -------------------------------------------------------------------------------------
-- 3) AudienciasExp: audiencias realizadas y programadas.
--
-- Ambas comparten estructura y se distinguen por indTipoAudiencia:
--   'REAL' -> audiencia ya realizada       (opcion audiencias_realizadas)
--   'PROG' -> audiencia programada a futuro (opcion proximas_audiencias)
--
-- Las columnas de acta, audio y enlace solo se pueblan para las realizadas.
-- -------------------------------------------------------------------------------------
CREATE TABLE If Not Exists u230756120_JURISDB_CHATB.AudienciasExp (
    id bigint unsigned primary key not null auto_increment,
    nUnico            BIGINT          COMMENT 'Numero unico del expediente.',
    xFormato          VARCHAR(100)    COMMENT 'Numero de formato completo del expediente.',
    nIncidente        CHAR(5)         COMMENT 'Numero de incidente del expediente.',
    xNomInstancia     VARCHAR(255)    COMMENT 'Nombre de la instancia judicial (juzgado).',
    especialista      VARCHAR(255)    COMMENT 'Nombre del especialista legal asignado al expediente.',
    indTipoAudiencia  CHAR(4)         COMMENT 'REAL = audiencia realizada, PROG = audiencia programada.',
    nProgramacion     INT NULL        COMMENT 'Numero de programacion de la audiencia en el SIJ.',
    nSala             INT NULL        COMMENT 'Numero de sala de audiencias.',
    lEstado           CHAR(10) NULL   COMMENT 'Estado de la programacion en el SIJ (REAL, PROG).',
    xDescAudiencia    VARCHAR(700) NULL COMMENT 'Descripcion de la audiencia.',
    fAudiencia        DATETIME NULL   COMMENT 'Fecha y hora de la audiencia (real si se realizo, programada si no).',
    xArchivoActa      VARCHAR(150) NULL COMMENT 'Nombre del archivo del acta. Solo audiencias realizadas.',
    xArchivoAudio     VARCHAR(150) NULL COMMENT 'Nombre del archivo de audio/video. Solo audiencias realizadas.',
    xEnlace           VARCHAR(500) NULL COMMENT 'URL publica de la grabacion, cuando el SIJ la registra como enlace.',
    regDate date Null Comment 'Fecha create',
    regDatetime datetime Null Comment 'Fecha Hora create',
    regTimestamp bigint Null Comment 'Epoch create',
    chatId char(100) NOT NULL Comment 'Id del Chat'
)
ENGINE = INNODB,
CHARACTER SET utf8mb4,
COLLATE utf8mb4_general_ci,
COMMENT = 'Tabla de Audiencias realizadas y programadas del Expediente';
-- Indexacion
ALTER TABLE u230756120_JURISDB_CHATB.AudienciasExp
    ADD INDEX nUnicoIDX (nUnico),
    ADD INDEX xFormatoIDX (xFormato),
    ADD INDEX indTipoAudienciaIDX (indTipoAudiencia),
    ADD INDEX fAudienciaIDX (fAudiencia),
    ADD INDEX regDateIDX (regDate),
    ADD INDEX regDatetimeIDX (regDatetime),
    ADD INDEX regTimestampIDX (regTimestamp),
    ADD INDEX chatIdIDX (chatId),
    -- El bot filtra por (chatId, nUnico, indTipoAudiencia) y ordena por fecha.
    ADD INDEX chatExpedienteTipoIDX (chatId, nUnico, indTipoAudiencia, fAudiencia);
