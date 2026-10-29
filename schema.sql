-- Esquema de la base de datos del sistema TBI / RBI / CIT (DTIC UNAP).
-- Generado desde la base en uso. Para una instalacion nueva ejecutar este archivo completo.
-- Para actualizar una base antigua NO volver a ejecutarlo: aplicar solo los ALTER de cada cambio.

CREATE DATABASE IF NOT EXISTS reportes_dtic CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE reportes_dtic;

-- Cuentas con login. rol: admin | responsable | encargado | activo_fijo. activo = 0 impide iniciar sesion sin borrar la cuenta ni sus registros.
CREATE TABLE usuarios (
  id int(11) NOT NULL AUTO_INCREMENT,
  nombre varchar(150) NOT NULL,
  correo varchar(150) NOT NULL,
  password_hash varchar(255) NOT NULL,
  rol enum('admin','responsable','encargado','activo_fijo') NOT NULL,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  activo tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ultimo folio usado por tipo y anio (se bloquea con FOR UPDATE al crear). Reinicia en 1 cada anio.
CREATE TABLE correlativos (
  tipo enum('entrega','recepcion','informe_tecnico') NOT NULL,
  anio int(11) NOT NULL,
  ultimo int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (tipo,anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Entregas (TBI), recepciones (RBI) e informes tecnicos (CIT). datos guarda los campos de la ficha en JSON.
CREATE TABLE solicitudes (
  id int(11) NOT NULL AUTO_INCREMENT,
  tipo enum('entrega','recepcion','informe_tecnico') NOT NULL,
  folio_num int(11) NOT NULL,
  anio int(11) NOT NULL,
  folio varchar(30) NOT NULL,
  fase_actual tinyint(4) NOT NULL DEFAULT 1,
  estado enum('en_curso','finalizada','anulada') NOT NULL DEFAULT 'en_curso',
  creado_por int(11) NOT NULL,
  solicitante_nombre varchar(150) NOT NULL,
  solicitante_correo varchar(150) NOT NULL,
  jefe_recibe_nombre varchar(150) NULL,
  jefe_recibe_correo varchar(150) NULL,
  solicitud_origen_id int(11) NULL,
  datos JSON NOT NULL,
  fase_desde datetime NOT NULL,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY folio (folio),
  KEY fk_sol_creador (creado_por),
  KEY fk_sol_origen (solicitud_origen_id),
  CONSTRAINT fk_sol_creador FOREIGN KEY (creado_por) REFERENCES usuarios (id),
  CONSTRAINT fk_sol_origen FOREIGN KEY (solicitud_origen_id) REFERENCES solicitudes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inventario. estado: en_dtic | asignado | baja | en_reparacion. bloqueado_por = solicitud en curso que lo reserva.
CREATE TABLE equipos (
  id int(11) NOT NULL AUTO_INCREMENT,
  codigo_inventario varchar(50) NULL,
  descripcion varchar(255) NOT NULL,
  serie varchar(100) NULL,
  estado enum('en_dtic','asignado','baja','en_reparacion') NOT NULL DEFAULT 'en_dtic',
  custodio_actual varchar(150) NULL,
  ubicacion_actual varchar(150) NULL,
  bloqueado_por int(11) NULL,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_equipo_bloqueo (bloqueado_por),
  CONSTRAINT fk_equipo_bloqueo FOREIGN KEY (bloqueado_por) REFERENCES solicitudes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Equipos incluidos en cada solicitud.
CREATE TABLE solicitud_equipos (
  solicitud_id int(11) NOT NULL,
  equipo_id int(11) NOT NULL,
  PRIMARY KEY (solicitud_id,equipo_id),
  KEY fk_se_equipo (equipo_id),
  CONSTRAINT fk_se_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id),
  CONSTRAINT fk_se_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Una fila por cada transicion de fase (crear, aprobar, rechazar, corregir, confirmar, finalizar, anular, reenviar).
CREATE TABLE historial (
  id int(11) NOT NULL AUTO_INCREMENT,
  solicitud_id int(11) NOT NULL,
  fase_origen tinyint(4) NULL,
  fase_destino tinyint(4) NULL,
  accion enum('crear','aprobar','rechazar','confirmar','rechazar_usuario','corregir','finalizar','anular','reenviar') NOT NULL,
  usuario_id int(11) NULL,
  actor_externo_nombre varchar(150) NULL,
  comentario text NULL,
  segundos_en_fase int(11) NULL,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_hist_solicitud (solicitud_id),
  KEY fk_hist_usuario (usuario_id),
  CONSTRAINT fk_hist_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes (id),
  CONSTRAINT fk_hist_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Links de un solo uso para los firmantes externos (se guarda solo el hash SHA-256 del token).
CREATE TABLE tokens_confirmacion (
  id int(11) NOT NULL AUTO_INCREMENT,
  solicitud_id int(11) NOT NULL,
  fase tinyint(4) NOT NULL,
  token_hash char(64) NOT NULL,
  destinatario_correo varchar(150) NOT NULL,
  destinatario_nombre varchar(150) NOT NULL,
  usado tinyint(1) NOT NULL DEFAULT 0,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_en datetime NOT NULL,
  usado_en datetime NULL,
  ip_uso varchar(45) NULL,
  PRIMARY KEY (id),
  KEY fk_token_solicitud (solicitud_id),
  KEY idx_token_hash (token_hash),
  CONSTRAINT fk_token_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Registro de los correos enviados.
CREATE TABLE notificaciones (
  id int(11) NOT NULL AUTO_INCREMENT,
  solicitud_id int(11) NOT NULL,
  destinatario_correo varchar(150) NOT NULL,
  asunto varchar(200) NOT NULL,
  enviado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exito tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  KEY fk_notif_solicitud (solicitud_id),
  CONSTRAINT fk_notif_solicitud FOREIGN KEY (solicitud_id) REFERENCES solicitudes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Nota obligatoria cada vez que un equipo en reparacion se marca como reparado.
CREATE TABLE equipo_movimientos (
  id int(11) NOT NULL AUTO_INCREMENT,
  equipo_id int(11) NOT NULL,
  accion varchar(30) NOT NULL,
  usuario_id int(11) NOT NULL,
  comentario varchar(500) NOT NULL,
  creado_en datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY fk_em_equipo (equipo_id),
  KEY fk_em_usuario (usuario_id),
  CONSTRAINT fk_em_equipo FOREIGN KEY (equipo_id) REFERENCES equipos (id) ON DELETE CASCADE,
  CONSTRAINT fk_em_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tiempo en fase actual y tiempo total por solicitud.
CREATE OR REPLACE VIEW v_solicitudes_tiempo AS
SELECT s.id AS solicitud_id, s.folio, s.tipo, s.fase_actual, s.estado,
    TIMESTAMPDIFF(SECOND, s.fase_desde, NOW()) AS segundos_en_fase_actual,
    (SELECT COALESCE(SUM(h.segundos_en_fase), 0) FROM historial h WHERE h.solicitud_id = s.id) AS segundos_totales
FROM solicitudes s;
