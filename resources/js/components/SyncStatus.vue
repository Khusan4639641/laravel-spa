<script setup>
import { computed } from 'vue';
import ErrorMessage from './ErrorMessage.vue';
const props = defineProps({ run: { type: Object, default: null } });
defineEmits(['retry']);
const labels = { pending: 'В очереди', processing: 'Синхронизация…', completed: 'Завершено', failed: 'Ошибка', blocked: 'Доступ ограничен' };
const active = computed(() => ['pending', 'processing'].includes(props.run?.status));
</script>
<template>
    <section v-if="run" class="sync-status" aria-label="Состояние синхронизации">
        <div class="section-heading">
            <strong>{{ labels[run.status] || run.status }}</strong>
            <span>{{ run.progress }}%</span>
        </div>
        <progress :value="run.progress" max="100" aria-label="Прогресс синхронизации" />
        <p role="status" aria-live="polite">{{ run.current_step }}</p>
        <p class="muted">Получено: {{ run.reviews_found }} · Сохранено: {{ run.reviews_saved }} · Попытка: {{ run.attempt || 0 }}</p>
        <p v-if="run.status === 'pending' && !run.attempt" class="muted">Синхронизация запущена. Ожидаем свободного обработчика.</p>
        <p v-if="run.coverage === 'available_only'" class="notice">Загружена доступная часть отзывов. Число отзывов на Яндексе может быть больше.</p>
        <ErrorMessage v-if="!active" :message="run.error_message || ''" />
        <button v-if="run.can_retry" type="button" class="button button--secondary" @click="$emit('retry')">Повторить</button>
    </section>
</template>
