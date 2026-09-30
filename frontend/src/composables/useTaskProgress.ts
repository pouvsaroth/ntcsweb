import { onBeforeUnmount, ref } from 'vue'

/**
 * Drives <TaskProgressOverlay> for a multi-step task (save, then create an
 * image on the server, then download it). A step whose real progress can't
 * be measured — the server rendering the image in one go — "creeps" toward
 * a target instead: fast at first, slowing as it nears it, never reaching
 * it until the next step (or done()) says so. A step that *can* be measured
 * (the download) sets the percentage directly with set().
 */
export function useTaskProgress() {
  /** 0–100 while running, null when hidden. */
  const percent = ref<number | null>(null)
  const label = ref('')
  let timer: ReturnType<typeof setInterval> | undefined

  function stop() {
    if (timer) clearInterval(timer)
    timer = undefined
  }

  /** Starts (or moves to) a step, easing from the current value toward `target`. */
  function creep(stepLabel: string, target: number) {
    stop()
    label.value = stepLabel
    percent.value ??= 0
    timer = setInterval(() => {
      if (percent.value === null) return
      percent.value += (target - percent.value) * 0.06
    }, 120)
  }

  /** A measured step — sets the value directly (never moving backward). */
  function set(stepLabel: string, value: number) {
    stop()
    label.value = stepLabel
    percent.value = Math.max(percent.value ?? 0, Math.min(100, value))
  }

  /** Fills the bar, then hides it a moment later. */
  async function done() {
    stop()
    percent.value = 100
    await new Promise((resolve) => setTimeout(resolve, 350))
    percent.value = null
  }

  function reset() {
    stop()
    percent.value = null
  }

  onBeforeUnmount(stop)

  return { percent, label, creep, set, done, reset }
}
