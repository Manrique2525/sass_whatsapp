<script setup lang="ts">
import LegalDocument from '@/Components/Marketing/LegalDocument.vue';
</script>

<template>
    <LegalDocument
        eyebrow="Información y privacidad"
        title="Política de privacidad"
        description="Conoce qué información puede procesar WhatsApp SaaS, cómo se utiliza y qué controles tienes sobre ella."
    >
        <h2>1. Información que recopilamos</h2>
        <p>WhatsApp SaaS puede procesar la información necesaria para prestar el servicio: datos de cuenta, información del espacio de trabajo, identificadores de contactos, contenido de conversaciones y metadatos de mensajes.</p>

        <h2>2. Cómo usamos la información</h2>
        <p>Usamos esta información para autenticar cuentas, operar el inbox, ejecutar automatizaciones, mostrar analítica del espacio de trabajo, mantener la seguridad y prestar soporte dentro de la plataforma.</p>

        <h2>3. Datos de cuenta</h2>
        <p>El nombre, email y credenciales de acceso se utilizan para administrar la cuenta. La pertenencia a espacios de trabajo, roles y permisos se usa para limitar el acceso según la configuración del negocio.</p>

        <h2>4. Datos de conversaciones</h2>
        <p>Si el negocio conecta WhatsApp Business, el servicio puede procesar mensajes, identificadores de contacto y estados de entrega para permitir la atención, automatización, asignación y seguimiento de conversaciones.</p>

        <h2>5. Integraciones de terceros</h2>
        <p>El negocio puede conectar servicios de terceros, como WhatsApp Business Cloud API. Algunas funciones pueden procesar información mediante proveedores configurados por la plataforma, de acuerdo con la operación de cada función. Consulta también las políticas de esos proveedores.</p>

        <h2>6. Seguridad</h2>
        <p>Aplicamos controles de autenticación, autorización por espacio de trabajo, validación de webhooks y protección de credenciales de integración. Ningún servicio puede garantizar seguridad absoluta.</p>

        <h2>7. Conservación de datos</h2>
        <p>Conservamos la información mientras sea necesaria para operar el espacio de trabajo, cumplir obligaciones aplicables, resolver disputas y hacer cumplir los acuerdos. Los plazos concretos pueden depender del tipo de dato y de la configuración del negocio.</p>

        <h2>8. Derechos y solicitudes</h2>
        <p>Las solicitudes relacionadas con acceso, corrección, eliminación o privacidad deben realizarse a través de los canales de soporte indicados dentro de la plataforma. Podemos solicitar información para verificar la identidad y el alcance de la solicitud.</p>

        <h2>9. Cambios a esta política</h2>
        <p>Podemos actualizar esta política cuando cambien el producto, los proveedores o las obligaciones aplicables. Publicaremos la versión vigente en esta página.</p>

        <h2>10. Contacto</h2>
        <p>Para solicitudes relacionadas con privacidad, utiliza los canales de soporte indicados dentro de la plataforma.</p>

        <p class="legal-note">Esta página es una base de producto y debe recibir revisión legal y empresarial final antes de cualquier lanzamiento público.</p>
    </LegalDocument>
</template>
