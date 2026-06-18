<script setup>
import { AlertCircle } from '@lucide/vue';

defineProps({
    message: {
        type: String,
        default: '',
    },
    errors: {
        type: Object,
        default: () => ({}),
    },
});
</script>

<template>
    <div v-if="message || Object.keys(errors).length" class="error-message" role="alert">
        <AlertCircle :size="18" aria-hidden="true" />
        <div>
            <p v-if="message" class="error-message__title">{{ message }}</p>
            <ul v-if="Object.keys(errors).length" class="error-message__list">
                <li v-for="(items, field) in errors" :key="field">
                    {{ Array.isArray(items) ? items[0] : items }}
                </li>
            </ul>
        </div>
    </div>
</template>
