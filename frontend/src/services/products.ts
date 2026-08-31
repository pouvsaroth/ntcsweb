import { apiDelete, apiGetWithMeta, apiPost, apiPut } from '@/services/http'
import type { PaginatedQuery } from '@/composables/usePaginatedResource'
import type { LengthAwarePaginationMeta, PaginatedResult } from '@/types/api'

export type ProductType = 'COURSE_FEE' | 'BOOK' | 'T_SHIRT' | 'UNIFORM' | 'CERTIFICATE' | 'OTHER'

export const productTypes: ProductType[] = ['COURSE_FEE', 'BOOK', 'T_SHIRT', 'UNIFORM', 'CERTIFICATE', 'OTHER']

export interface ProductVariant {
  id: number
  product_id: number
  name: string
  price_override: number | null
  is_active: boolean
}

export interface Product {
  id: number
  code: string
  name: string
  description: string | null
  type: ProductType
  price: number
  is_active: boolean
  /** Present on `show`/`update`; absent (not empty) on the list endpoint. */
  variants?: ProductVariant[]
  created_at: string
}

export interface ProductInput {
  code: string
  name: string
  description: string
  type: ProductType
  /** Empty string on a blank field — the caller trims/converts before sending. */
  price: string
  is_active: boolean
}

export interface ProductVariantInput {
  name: string
  /** Empty string means "no override" — falls back to the product's own price. */
  price_override: string
  is_active: boolean
}

function toProductPayload(input: ProductInput) {
  return {
    code: input.code,
    name: input.name,
    description: input.description || null,
    type: input.type,
    price: Number(input.price) || 0,
    is_active: input.is_active,
  }
}

function toVariantPayload(input: ProductVariantInput) {
  return {
    name: input.name,
    price_override: input.price_override.trim() ? Number(input.price_override) : null,
    is_active: input.is_active,
  }
}

export const productsService = {
  async list(query: PaginatedQuery): Promise<PaginatedResult<Product>> {
    const result = await apiGetWithMeta<Product[]>('/products', {
      params: { page: query.page, per_page: query.per_page, search: query.search, sort: query.sort, filter: query.filter },
    })

    return { data: result.data, pagination: result.meta?.pagination as LengthAwarePaginationMeta }
  },

  /**
   * Every product, for the invoice form's line-item picker — filtered to
   * active ones client-side rather than via `filter[is_active]`, since that
   * would bind a string against a boolean column. A school's catalog is
   * small, same assumption as books/classes' own listAll().
   */
  async listAllActive(): Promise<Product[]> {
    const result = await apiGetWithMeta<Product[]>('/products', { params: { per_page: 200, sort: 'name' } })
    return result.data.filter((product) => product.is_active)
  },

  get: (id: number) => apiGetWithMeta<Product>(`/products/${id}`).then((r) => r.data),

  create: (input: ProductInput) => apiPost<Product>('/products', toProductPayload(input)),

  update: (id: number, input: ProductInput) => apiPut<Product>(`/products/${id}`, toProductPayload(input)),

  remove: (id: number) => apiDelete(`/products/${id}`),

  createVariant: (productId: number, input: ProductVariantInput) =>
    apiPost<ProductVariant>(`/products/${productId}/variants`, toVariantPayload(input)),

  updateVariant: (variantId: number, input: ProductVariantInput) =>
    apiPut<ProductVariant>(`/product-variants/${variantId}`, toVariantPayload(input)),

  removeVariant: (variantId: number) => apiDelete(`/product-variants/${variantId}`),
}
