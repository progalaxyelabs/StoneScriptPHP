import { Injectable } from '@angular/core';

export enum EditorTools {
    pages,
    currentPage,
    layouts,
    editSection
}


@Injectable({
    providedIn: 'root'
})
export class EditorToolsService {

    EditorTools = EditorTools
    activeTool = EditorTools.pages

    constructor() { }

    showEditPageTools() {
        this.activeTool = EditorTools.currentPage
    }

    showLayouts() {
        this.activeTool =  EditorTools.layouts
    }

    showEditSectionTools() {
        this.activeTool = EditorTools.editSection
    }
}
