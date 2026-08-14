import type { Component } from 'vue';
import MessageNode from './MessageNode.vue';
import ButtonsNode from './ButtonsNode.vue';
import QuestionNode from './QuestionNode.vue';
import ConditionNode from './ConditionNode.vue';
import DelayNode from './DelayNode.vue';
import TagNode from './TagNode.vue';
import WebhookNode from './WebhookNode.vue';
import AINode from './AINode.vue';
import HumanNode from './HumanNode.vue';
import EndNode from './EndNode.vue';

/**
 * Registro de tipos de nodo de Vue Flow (FASE 12). La clave es el `type` del
 * payload de la API (FASE 11). `ai` se registra con visual bloqueado (no
 * instanciable desde el editor).
 */
export const flowNodeTypes: Record<string, Component> = {
    message: MessageNode,
    buttons: ButtonsNode,
    question: QuestionNode,
    condition: ConditionNode,
    delay: DelayNode,
    tag: TagNode,
    webhook: WebhookNode,
    ai: AINode,
    human: HumanNode,
    end: EndNode,
};
