export type WebsiteType = 'portfolio' | 'business' | 'ecommerce' | 'blog' | 'erp'

export interface Website {
    name: string
    type: WebsiteType
    url: string
}