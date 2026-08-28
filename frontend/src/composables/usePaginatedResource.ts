import { computed, ref, watch } from 'vue'

import { ApiRequestError, type LengthAwarePaginationMeta, type PaginatedResult } from '@/types/api'

export interface PaginatedQuery {
  page: number
  per_page: number
  search?: string
  sort?: string
  filter?: Record<string, string>
}

/**
 * Drives an admin list screen against a length-aware paginated endpoint
 * (App\Support\Query\ApiQuery::paginate() on the backend). Deliberately never
 * accumulates rows across pages and never fetches "all" of anything — with
 * tables designed to hold millions of records, the only safe default is
 * "one page's worth of rows in memory, always," everything else (search,
 * sort, filter) is pushed to the server via query params it already
 * allow-lists.
 *
 * Search is debounced client-side so typing doesn't fire a request per
 * keystroke against a large table.
 */
export function usePaginatedResource<T>(
  fetcher: (query: PaginatedQuery) => Promise<PaginatedResult<T>>,
  options: { perPage?: number; debounceMs?: number } = {},
) {
  const items = ref<T[]>([])
  const meta = ref<LengthAwarePaginationMeta | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  const page = ref(1)
  const perPage = ref(options.perPage ?? 25)
  const search = ref('')
  const sort = ref<string | undefined>(undefined)
  const filters = ref<Record<string, string>>({})

  let debounceHandle: ReturnType<typeof setTimeout> | undefined
  let requestToken = 0

  async function fetch(): Promise<void> {
    const token = ++requestToken
    loading.value = true
    error.value = null

    try {
      const result = await fetcher({
        page: page.value,
        per_page: perPage.value,
        search: search.value || undefined,
        sort: sort.value,
        filter: filters.value,
      })

      // A slower, stale request that resolves after a newer one must not
      // clobber the newer result — otherwise fast typing in the search box
      // can flash outdated rows back onto the screen.
      if (token !== requestToken) return

      items.value = result.data
      meta.value = result.pagination.type === 'length_aware' ? result.pagination : null
    } catch (e) {
      if (token !== requestToken) return
      error.value = e instanceof ApiRequestError ? e.message : 'Failed to load data.'
    } finally {
      if (token === requestToken) loading.value = false
    }
  }

  function setPage(next: number): void {
    page.value = next
    void fetch()
  }

  function setSearch(term: string): void {
    search.value = term
    page.value = 1
    clearTimeout(debounceHandle)
    debounceHandle = setTimeout(() => void fetch(), options.debounceMs ?? 350)
  }

  function setSort(next: string | undefined): void {
    sort.value = next
    void fetch()
  }

  function setFilter(key: string, value: string | undefined): void {
    if (value === undefined || value === '') {
      const { [key]: _removed, ...rest } = filters.value
      filters.value = rest
    } else {
      filters.value = { ...filters.value, [key]: value }
    }
    page.value = 1
    void fetch()
  }

  const isEmpty = computed(() => !loading.value && items.value.length === 0)

  watch(perPage, () => {
    page.value = 1
    void fetch()
  })

  return {
    items,
    meta,
    loading,
    error,
    page,
    perPage,
    search,
    sort,
    isEmpty,
    fetch,
    setPage,
    setSearch,
    setSort,
    setFilter,
  }
}
