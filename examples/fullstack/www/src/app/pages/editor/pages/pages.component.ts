import { Component, OnInit } from '@angular/core';
import { EditorToolsService } from '../editor-tools.service';
import { WebsiteService } from '../website.service';
import { NgFor } from '@angular/common';

@Component({
    selector: 'app-pages',
    templateUrl: './pages.component.html',
    styleUrls: ['./pages.component.css'],
    imports: [NgFor]
})
export class PagesComponent implements OnInit {

    constructor(
        public websiteService: WebsiteService,
        private editorTools: EditorToolsService
        ) { }

    ngOnInit(): void {
    }

    onWebsitePageClick(index: number) {
        this.websiteService.activeWebsite.selectedWebsitePageIndex = index
    }

    onDeletePageClick() {
        throw new Error('Delete Page Not Implemented')
    }

    onEditPageClick() {
        this.editorTools.showEditPageTools()
    }

}
