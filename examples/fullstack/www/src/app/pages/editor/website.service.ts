import { ElementRef, Injectable } from '@angular/core';
import { BehaviorSubject, Subject } from 'rxjs';
import { v4 as uuidv4 } from 'uuid';

export class WebsiteSection {
    element: HTMLElement
    id: string
    name: string
    properties: any
    showGridLines: boolean

    static GenerateId(): string {
        return 'section' + Date.now()
    }

    constructor(name: string, element: HTMLElement) {
        this.name = name
        this.element = element
        this.id = WebsiteSection.GenerateId()
        this.element.id = this.id
        this.properties = {
            background: 'initial'
        }
        this.showGridLines = true
        this.element.classList.add('show-grid-lines')
    }

    toggleGridLines() {
        this.showGridLines = !this.showGridLines
        if (this.showGridLines) {
            this.element.classList.add('show-grid-lines')
        } else {
            this.element.classList.remove('show-grid-lines')
        }
    }
}

export class WebsitePage {
    title = ''
    description = ''
    keywords = ''
    fileName = ''
    sections: WebsiteSection[] = []
}

export type WebsiteType = 'portfolio' | 'business' | 'ecommerce' | 'blog' | 'erp' | 'blank'

export class Website {
    // name: string
    // url: string
    // type: WebsiteType
    readonly id: string
    pages: WebsitePage[] = []

    private logo = ''
    private favicon = ''
    // private domainName = ''
    private iframeDocument!: Document
    colors: Colors = new Colors()

    selectedWebsiteSectionIndex = -1
    selectedWebsitePageIndex = -1

    activePage!: WebsitePage

    onLayoutSelected: Subject<boolean>
    onActiveSectionBackgroundChange: Subject<string>
    private dom: DOM


    constructor(
        public name: string,
        public type: WebsiteType,
        public url: string
    ) {
        this.id = uuidv4()

        const indexPage = new WebsitePage()
        indexPage.fileName = 'index.html'
        indexPage.title = 'Home'
        this.pages.push(indexPage)
        this.activePage = indexPage
        this.selectedWebsitePageIndex = 0

        this.dom = new DOM()

        this.onLayoutSelected = new Subject<boolean>()

        this.onActiveSectionBackgroundChange = new Subject<string>()

        this.onActiveSectionBackgroundChange.subscribe((background: string) => {
            this.updateActiveSectionBackground(background)
        })
    }

    getWebsiteTypeStr(): string {
        switch (this.type) {
            case 'portfolio':
                return 'Portfolio website';
            case 'business':
                return 'Business website';
            case 'ecommerce':
                return 'eCommerce website';
            case 'blog':
                return 'Blog';
            default:
                return 'Not selected';
        }
    }

    initIFrame(iframe: ElementRef<HTMLIFrameElement>, headTemplate: ElementRef<HTMLTemplateElement>) {
        setTimeout(() => {
            if (iframe.nativeElement.contentDocument) {
                this.dom.init(iframe.nativeElement.contentDocument)
                this.addBootstrap53()
            }
        }, 100)
    }

    appendWebsiteSection(websiteSection: WebsiteSection): void {
        if (this.iframeDocument.scripts) {
            const firstScript = this.iframeDocument.scripts[0]
            this.iframeDocument.body.insertBefore(websiteSection.element, firstScript)
        } else {
            this.iframeDocument.body.appendChild(websiteSection.element)
        }
        this.activePage.sections.push(websiteSection)
        this.selectSection(this.activePage.sections.length - 1)
    }

    createElement(tagName: string) {
        return this.iframeDocument.createElement(tagName)
    }

    updateActiveSectionBackground(background: string) {
        if (this.selectedWebsitePageIndex === -1) {
            return
        }
        const websiteSection = this.activePage.sections[this.selectedWebsiteSectionIndex]
        websiteSection.properties.background = background
        websiteSection.element.style.background = background
    }

    selectSection(index: number) {
        this.selectedWebsiteSectionIndex = index
        this.activePage.sections[index].element.scrollIntoView({ behavior: 'smooth' })
    }

    addNavbar(navbar: Navbar) {
        this.dom.navbar(navbar)
    }

    addSection() {
        const element = document.createElement('section')
        const section = new WebsiteSection('Section', element)
        this.appendWebsiteSection(section)

    }

    addBootstrap53() {
        this.dom.link(
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            'sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
            'anonymous'
        )

        this.dom.script(
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            'sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
            'anonymous'
        )
    }
}

export class Colors {

    colors = ['orange', 'cyan', 'purple', 'yellow']
    lastColorIndex = -1

    getNextColor() {
        this.lastColorIndex++
        if (this.lastColorIndex === this.colors.length) {
            this.lastColorIndex = 0
        }
        return this.colors[this.lastColorIndex]
    }
}


export type NavbarLink = {
    label: string
    url: string
    icon?: string
    as: 'link' | 'dropdown' | 'button'
    disabled?: boolean
}
export type Navbar = {
    brandName: string
    logoUrl: string
    menu: NavbarLink[]
}

export class DOM {
    document: Document
    constructor() {
        this.document = new Document
    }
    init(doc: Document) {
        this.document = doc

        const charsetElement = this.document.createElement('meta')
        charsetElement.setAttribute('charset', 'utf-8')
        this.document.head.append(charsetElement)

        const baseElement = this.document.createElement('base')
        baseElement.href = '/'
        this.document.head.append(baseElement)

        const viewportElement = this.document.createElement('meta')
        viewportElement.setAttribute('name', 'viewport')
        viewportElement.setAttribute('content', 'width=device-width, initial-scale=1')
        this.document.head.append(viewportElement)
    }

    link(href: string, integrity?: string, crossorigin?: string): void {
        const element = this.document.createElement('link')
        element.href = href
        element.rel = 'stylesheet'
        if (integrity) {
            element.integrity = integrity
        }
        if (crossorigin) {
            element.crossOrigin = crossorigin
        }

        this.document.head.append(element)
    }

    script(src: string, integrity?: string, crossorigin?: string): void {
        const element = this.document.createElement('script')
        element.src = src
        if (integrity) {
            element.integrity = integrity
        }
        if (crossorigin) {
            element.crossOrigin = crossorigin
        }

        this.document.body.append(element)
    }

    navbar(navbar: Navbar) {
        const nav = this.document.createElement('nav')
        nav.classList.add('navbar', 'navbar-expand-lg', 'bg-body-tertiary')
        this.document.body.insertAdjacentElement('afterbegin', nav)

        const containerFluid = this.document.createElement('div')
        containerFluid.className = 'container-fluid'
        nav.append(containerFluid)

        const navbarBrand = this.document.createElement('a')
        navbarBrand.className = 'navbar-brand'
        navbarBrand.href = '#'
        navbarBrand.textContent = navbar.brandName
        containerFluid.append(navbarBrand)

        const navbarToggler = this.document.createElement('button')
        navbarToggler.className = 'navbar-toggler'
        navbarToggler.type = 'button'
        navbarToggler.setAttribute('data-bs-toggle', 'collapse')
        navbarToggler.setAttribute('data-bs-target', '#navbarSupportedContent')
        navbarToggler.setAttribute('aria-controls', 'navbarSupportedContent')
        navbarToggler.setAttribute('aria-expanded', 'false')
        navbarToggler.setAttribute('aria-label', 'Toggle Navigation')
        const navbarTogglerIcon = this.document.createElement('span')
        navbarTogglerIcon.className = 'navbar-toggler-icon'
        navbarToggler.append(navbarTogglerIcon)
        containerFluid.append(navbarToggler)

        const navbarCollapse = this.document.createElement('div')
        navbarCollapse.id = 'navbarSupportedContent'
        navbarCollapse.classList.add('collapse', 'navbar-collapse')
        containerFluid.append(navbarCollapse)

        const navbarNav = this.document.createElement('ul')
        navbarNav.classList.add('navbar-nav', 'me-auto', 'mb-2', 'mb-lg-0')
        navbarCollapse.append(navbarNav)

        for (let menuItem of navbar.menu) {
            const navItem = this.document.createElement('li')
            navItem.className = 'nav-item'

            const a = this.document.createElement('a')
            a.className = 'nav-link'

            if (menuItem.as === 'dropdown') {
                navItem.classList.add('dropdown')
                a.classList.add('dropdown-toggle')
                a.href = '#'
                a.role = 'button'
                a.setAttribute('data-bs-toggle', 'dropdown')
                a.setAttribute('aria-expanded', 'false')
                a.textContent = menuItem.label
            } else {
                a.href = menuItem.url
                a.textContent = menuItem.label
            }

            if (menuItem.disabled) {
                a.classList.add('disabled')
                a.setAttribute('aria-disabled', 'true')
            }

            navItem.append(a)

            if (menuItem.as === 'dropdown') {
                const dropdownMenu = this.document.createElement('ul')
                dropdownMenu.className = 'dropdown-menu'
                navItem.append(dropdownMenu)

                const li = this.document.createElement('li')
                dropdownMenu.append(li)

                const dropdownItem = this.document.createElement('a')
                dropdownItem.href = '#'
                dropdownItem.className = 'dropdown-item'
                dropdownItem.textContent = 'Item 1'
                li.append(dropdownItem)
            }

            navbarNav.append(navItem)
        }
    }
}

@Injectable({
    providedIn: 'root'
})
export class WebsiteService {

    websites: Website[] = []
    readonly blankWebsite: Website

    activeWebsite: Website

    constructor() {
        this.blankWebsite= new Website('', 'blank', '')
        this.websites.push(this.blankWebsite)
        this.activeWebsite = this.blankWebsite
    }

    addWebsite(website: Website) {
        this.websites.push(website)
    }
}
