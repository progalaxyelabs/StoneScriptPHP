import { Component, OnInit } from '@angular/core';
import { EditorToolsService } from '../editor-tools.service';
import { WebsiteSection, WebsiteService } from '../website.service';
import { NgFor, NgIf } from '@angular/common';

@Component({
    selector: 'app-sections',
    templateUrl: './sections.component.html',
    styleUrls: ['./sections.component.css'],
    imports: [NgFor, NgIf]
})
export class SectionsComponent implements OnInit {

    constructor(
        public websiteService: WebsiteService,
        public editorTools: EditorToolsService
    ) { }

    ngOnInit(): void {
    }

    onAddSectionClick() {
        this.editorTools.showLayouts()
    }

    onEditSectionClick() {
        this.editorTools.showEditSectionTools()
    }

    onWebsiteSectionClick(index: number) {
        this.websiteService.activeWebsite?.selectSection(index)
    }

    onDeleteSectionClick(event: Event) {
        throw new Error('Delete Section Not Implemented')
    }
}
