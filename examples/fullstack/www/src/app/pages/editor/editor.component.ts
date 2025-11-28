import { AfterViewInit, Component, ElementRef, OnInit, ViewChild, ViewEncapsulation } from '@angular/core';
import { EditorTools, EditorToolsService } from './editor-tools.service';
import { WebsiteService } from './website.service';
import { NgClass } from '@angular/common';
import { PagesComponent } from './pages/pages.component';
import { LayoutsComponent } from './layouts/layouts.component';
import { SectionsComponent } from './sections/sections.component';
import { CustomizeSectionComponent } from './customize-section/customize-section.component';

@Component({
    selector: 'app-editor',
    templateUrl: './editor.component.html',
    styleUrls: ['./editor.component.css'],
    encapsulation: ViewEncapsulation.None,
    imports: [
        NgClass,
        PagesComponent,
        LayoutsComponent,
        SectionsComponent,
        CustomizeSectionComponent,
    ]
})
export class EditorComponent implements OnInit, AfterViewInit {

    @ViewChild('headTemplate', {
        read: ElementRef,
        static: true
    })
    headTemplate!: ElementRef<HTMLTemplateElement>;

    @ViewChild('iframe1', {
        read: ElementRef,
        static: false
    })
    iframe1!: ElementRef<HTMLIFrameElement>;

    EditorTools = EditorTools

    previewDeviceType = 'desktop' // desktop, tablet, mobile
    // activeTool = EditorTools.sections
    editorToolsOpen = true

    movies = [
        // 'Episode I - The Phantom Menace',
        // 'Episode II - Attack of the Clones',
        // 'Episode III - Revenge of the Sith',
        // 'Episode IV - A New Hope',
        // 'Episode V - The Empire Strikes Back',
        // 'Episode VI - Return of the Jedi',
        // 'Episode VII - The Force Awakens',
        // 'Episode VIII - The Last Jedi'
    ];

    constructor(
        public websiteService: WebsiteService,
        public editorTools: EditorToolsService
    ) {
        this.editorTools.activeTool = EditorTools.currentPage
    }

    ngOnInit(): void {
        this.websiteService.activeWebsite.onLayoutSelected.subscribe((ok: boolean) => {
            if (ok) {

            }
            this.editorTools.activeTool = EditorTools.currentPage
        })
    }

    ngAfterViewInit(): void {
        this.websiteService.activeWebsite.initIFrame(this.iframe1, this.headTemplate)
        // this.website.addBootstrap53()
        this.setPreviewDeviceType('desktop')
    }

    setPreviewDeviceType(deviceType: string) {
        this.previewDeviceType = deviceType
    }

    addNavbar() {
        this.websiteService.activeWebsite.addNavbar({
            brandName: 'Web Meteor',
            logoUrl: 'http://localhost:4200/assets/webmeteor.png',
            menu: [
                {label: 'Home', url: '/', as: 'link'},
                {label: 'Link', url: '/', as: 'link'},
                {label: 'Dropdown', url: '/', as: 'dropdown'},
            ]
        })
    }

    addSection() {
        this.websiteService.activeWebsite.addSection()
    }

}
