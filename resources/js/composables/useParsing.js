import { computed, onUnmounted, ref } from 'vue';
import http from '../api/http';

export function useParsing(onFinished) {
    const run = ref(null);
    const pollingError = ref('');
    const active = computed(() => ['pending', 'processing'].includes(run.value?.status));
    let timer;
    let controller;
    let generation = 0;
    let failures = 0;

    function stop() {
        generation++;
        clearTimeout(timer);
        controller?.abort();
    }
    async function poll(id, version) {
        controller = new AbortController();
        try {
            const { data } = await http.get(`/api/parsing-runs/${id}`, { signal: controller.signal });
            if (version !== generation) return;
            run.value = data.data;
            failures = 0;
            pollingError.value = '';
            if (active.value) {
                timer = setTimeout(() => poll(id, version), 2000);
            } else {
                await onFinished(run.value);
            }
        } catch (error) {
            if (version !== generation || error.code === 'ERR_CANCELED') return;
            pollingError.value = 'Не удалось обновить состояние синхронизации. Проверьте соединение.';
            failures++;
            if (error.response?.status === 401 || error.response?.status === 404 || failures >= 5) return;
            timer = setTimeout(() => poll(id, version), Math.min(15000, 2000 * failures));
        }
    }
    function start(value) {
        stop();
        run.value = value;
        pollingError.value = '';
        failures = 0;
        if (active.value) poll(value.id, generation);
    }
    function resume() {
        if (run.value && active.value) start(run.value);
    }
    onUnmounted(stop);
    return { run, active, pollingError, start, stop, resume };
}
