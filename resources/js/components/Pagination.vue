<script setup>
import { ChevronLeft, ChevronRight } from '@lucide/vue';

const props = defineProps({
    meta: {
        type: Object,
        required: true,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['change']);

function goTo(page) {
    if (props.disabled || page < 1 || page > props.meta.last_page || page === props.meta.current_page) {
        return;
    }

    emit('change', page);
}
</script>

<template>
    <nav v-if="meta.last_page > 1" class="pagination" aria-label="Пагинация отзывов">
        <button
            class="icon-button"
            type="button"
            :disabled="disabled || meta.current_page <= 1"
            title="Предыдущая страница"
            @click="goTo(meta.current_page - 1)"
        >
            <ChevronLeft :size="18" aria-hidden="true" />
        </button>

        <span class="pagination__status">
            {{ meta.current_page }} / {{ meta.last_page }}
        </span>

        <button
            class="icon-button"
            type="button"
            :disabled="disabled || meta.current_page >= meta.last_page"
            title="Следующая страница"
            @click="goTo(meta.current_page + 1)"
        >
            <ChevronRight :size="18" aria-hidden="true" />
        </button>
    </nav>
</template>
