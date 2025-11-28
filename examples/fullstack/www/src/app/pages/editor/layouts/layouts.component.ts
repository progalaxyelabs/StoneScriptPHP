import { Component, ElementRef, OnInit, ViewChild } from '@angular/core';
import { LayoutService } from '../layout.service';
import { WebsiteSection, WebsiteService } from '../website.service';
import { NgFor, NgClass } from '@angular/common';

class SectionTemplate {
    imgUrl = ''
    imgFileNumber = ''

    constructor(imgFileNumber: string) {
        if (imgFileNumber) {
            this.imgFileNumber = imgFileNumber
            this.imgUrl = '/assets/editor/layouts/' + imgFileNumber + '.jpg'
        }
    }
}

const NoTemplate = new SectionTemplate('')

@Component({
    selector: 'app-layouts',
    templateUrl: './layouts.component.html',
    styleUrls: ['./layouts.component.css'],
    imports: [NgFor, NgClass]
})
export class LayoutsComponent implements OnInit {
    @ViewChild('coverTemplate', {
        read: ElementRef,
        static: true
    })
    coverTemplate!: ElementRef<HTMLTemplateElement>;

    selectedTemplateIndex = -1
    selectedTemplate: SectionTemplate = NoTemplate
    // templates: SectionTemplate[] = []
    previewModalOpen = false

    static NoTemplate = new SectionTemplate('')

    templates = [
        5, 5, 5, 4, 6, 4, 6, 4, 6, 5,
        5, 5, 4, 7, 4, 4, 4, 4, 4, 4,
        5, 4, 4, 5, 6, 5, 6, 5, 6, 5,
        3, 4, 3, 3, 3, 6, 7, 5, 4, 4,
        5, 6, 3, 4, 4, 3, 3, 4, 5, 6,
        6, 7, 3, 4, 4, 3, 6, 5, 7, 6,
        3, 3, 4, 3, 4, 4, 4, 4, 4, 4,
        4, 4, 4, 4, 4, 5, 5, 4, 4, 4,
        4, 4, 4, 4, 5, 5, 3, 4, 4, 4,
        4, 4, 4, 4, 4, 6, 5, 5, 4, 4,
        4, 4, 4, 4, 4, 8, 7, 5, 5, 6,
        6, 6, 4, 5, 5, 5, 5, 5, 5, 5,
        5, 5, 5, 5, 4, 5, 5, 5, 5, 5,
        6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        6, 6, 6, 6, 6, 6, 6, 7, 6, 6,
        6, 6, 7, 7, 8, 6, 6, 6, 6, 6,
        6, 6, 7, 6, 5, 6, 7, 7, 7, 7,
        8, 8, 8, 8, 9, 8, 7, 7, 7, 8,
        8, 7, 7, 7, 8, 8, 8, 7, 7, 7,
        8, 8, 6, 6, 7, 6, 6, 6, 7, 7,
        6, 5, 5, 6, 6, 7, 5, 4
    ]

    constructor(
        private layout: LayoutService,
        private websiteService: WebsiteService
    ) {
        // for (let i = 1; i <= 208; i++) {
        //     this.templates.push(new SectionTemplate((i < 10 ? '00' : i < 100 ? '0' : '') + i))            
        // }
    }

    ngOnInit(): void {

    }


    // onOkClick() {
    //     this.website.onLayoutSelected.next(true)
    // }

    // onCancelClick() {
    //     this.website.onLayoutSelected.next(false)
    // }

    onPreviewClick() {
        // this.selectedTemplate = this.templates[this.selectedTemplateIndex]
        this.previewModalOpen = true
    }

    onSelectLayoutAddClick(i: number) {
        this.selectedTemplateIndex = i
        this.insertLayout()        
        // this.onOkClick()
    }

    onSelectLayoutPreviewClick(i: number) {
        this.selectedTemplateIndex = i
        this.onPreviewClick()
    }

    onPreviewModalCloseClick() {
        this.previewModalOpen = false
        this.selectedTemplate = NoTemplate
    }

    private insertLayout() {
        const gridElement: HTMLElement = this.websiteService.activeWebsite.createElement('div')
        gridElement.className = this.templateGridClass(this.selectedTemplateIndex + 1)
        gridElement.setAttribute('data-number', this.templateDataNumber(this.selectedTemplateIndex + 1))
        const gridCellsCount = this.templates[this.selectedTemplateIndex]
        let i: number, cellElement: HTMLElement
        for(let i = 0; i < gridCellsCount; i++) {
            cellElement = this.websiteService.activeWebsite.createElement('div')
            cellElement.className = this.templateGridCellClass(i + 1)
            gridElement.appendChild(cellElement)
        }
        const websiteSection = new WebsiteSection('Change section name', gridElement)

        this.websiteService.activeWebsite.appendWebsiteSection(websiteSection)
    }

    private insertBlankLayout() {
        const section = this.websiteService.activeWebsite.createElement('section')
        section.style.minHeight = '100vh'
        section.style.backgroundColor = this.websiteService.activeWebsite.colors.getNextColor()
        const websiteSection = new WebsiteSection('Section', section)
        this.websiteService.activeWebsite.appendWebsiteSection(websiteSection)
    }

    private insertCoverLayout(): void {
        const content = document.importNode(this.coverTemplate.nativeElement, true).content
        const element = content.firstElementChild as HTMLElement
        if (!element) {
            console.warn('failed to add cover section')
            return
        }
        const websiteSection = new WebsiteSection('Cover Section', element)
        this.websiteService.activeWebsite.appendWebsiteSection(websiteSection)
    }

    templateGridClass(i : number): string {
        return 'wm-template wm-template-' + ((i < 10 ? '00' : i < 100 ? '0' : '') + i)
    }

    templateDataNumber(i : number): string {
        return '#' + ((i < 10 ? '00' : i < 100 ? '0' : '') + i)
    }

    templateGridCells(i : number): number[] {
        return Array(i)
    }

    templateGridCellClass(i : number): string {
        return 'wm-cell wm-cell-' + i
    }
}
