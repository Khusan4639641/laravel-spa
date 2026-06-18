<script setup>
defineProps({
    label: {
        type: String,
        default: 'Loading',
    },
});
</script>

<template>
    <div class="loading-state" role="status" aria-live="polite">
        <span class="spinner" aria-hidden="true"></span>
        <span>{{ label }}</span>
    </div>
</template>
