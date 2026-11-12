-- Tabla para plantillas de términos y condiciones
-- Permite guardar diferentes sets de términos para diferentes tipos de proyectos

CREATE TABLE IF NOT EXISTS plantillas_terminos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL COMMENT 'Nombre descriptivo de la plantilla (ej: Ecommerce, Web Corporativa)',
    descripcion VARCHAR(255) DEFAULT NULL COMMENT 'Descripción breve de cuándo usar esta plantilla',
    terminos_obligaciones TEXT DEFAULT NULL COMMENT 'Obligaciones del cliente específicas de esta plantilla',
    terminos_desarrollo TEXT DEFAULT NULL COMMENT 'Proceso de desarrollo específico de esta plantilla',
    terminos_condiciones TEXT DEFAULT NULL COMMENT 'Términos y condiciones específicos de esta plantilla',
    activa TINYINT(1) DEFAULT 1 COMMENT 'Si la plantilla está disponible para usar',
    orden INT DEFAULT 0 COMMENT 'Orden de aparición en el selector',
    creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_activa (activa),
    INDEX idx_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar plantillas de ejemplo
INSERT INTO plantillas_terminos (nombre, descripcion, terminos_obligaciones, terminos_desarrollo, terminos_condiciones, orden) VALUES
('Ecommerce', 'Tienda online con productos y pagos', 
'• Enviar fotografías de productos (mínimo 800x800px, fondo blanco preferible)\n• Lista de productos con: nombre, SKU, precio, descripción, stock\n• Información de envíos: zonas, costos, tiempos\n• Datos de cuenta bancaria o contrato con pasarela de pago (Webpay, Flow, etc.)\n• Acceso a fanpage de Facebook (para Instagram Shopping)', 
'• Tiempo estimado: 30-45 días hábiles con todo el contenido\n• Demo con 5 productos de ejemplo para validar diseño\n• Capacitación de 1 hora para uso del panel de admin\n• Entrega con hasta 20 productos cargados (resto por el cliente o cotizar aparte)', 
'• 50% al inicio, 50% contra entrega\n• La cotización de carga de productos adicionales es aparte\n• Mantención mensual incluida por 3 meses (luego se cotiza aparte)\n• No incluye fotografía de productos', 
1),

('Web Corporativa', 'Sitio institucional informativo', 
'• Enviar fotografías de la empresa/equipo\n• Textos para cada sección (Nosotros, Servicios, etc.)\n• Logotipo en formato vectorial (AI, EPS, SVG) o PNG de alta calidad\n• Datos de contacto: dirección, teléfonos, emails\n• Redes sociales existentes', 
'• Tiempo estimado: 15-20 días hábiles\n• 2 rondas de revisiones incluidas\n• Entrega con todo el contenido cargado\n• Capacitación de 30 minutos para actualizar noticias/blog', 
'• 50% al inicio, 50% contra entrega\n• Incluye 1 año de hosting básico\n• No incluye redacción de contenidos\n• Cambios de diseño después de aprobado tienen costo adicional', 
2),

('Landing Page', 'Página de aterrizaje para campaña', 
'• Objetivo de la campaña (leads, ventas, registro)\n• Textos persuasivos para cada sección\n• Imagen principal o video de fondo\n• Formulario: qué datos solicitar (nombre, email, teléfono, etc.)\n• Integración con email marketing (Mailchimp, etc.) si aplica', 
'• Tiempo estimado: 5-7 días hábiles\n• Diseño optimizado para conversión\n• Versión mobile-first\n• 1 ronda de revisiones', 
'• Pago 100% al inicio por ser proyecto corto\n• No incluye copywriting avanzado\n• No incluye imágenes de stock premium (se usan gratuitas o el cliente compra)', 
3),

('Sistema Web', 'Aplicación web a medida', 
'• Documento de requerimientos detallado\n• Flujos de usuario (user flows)\n• Casos de uso y roles de usuarios\n• Mockups o wireframes si existen\n• Integraciones con APIs de terceros especificadas', 
'• Tiempo estimado según alcance (se define en propuesta)\n• Metodología ágil con sprints quincenales\n• Entregas parciales funcionales\n• Testing incluido\n• Documentación técnica básica', 
'• 40% al inicio, 30% a la mitad, 30% contra entrega\n• Cambios de alcance se cotizan aparte\n• Mantención por 6 meses incluida\n• Hosting y dominio por cuenta del cliente', 
4);
