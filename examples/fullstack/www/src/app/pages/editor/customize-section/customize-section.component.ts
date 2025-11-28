import { Component, OnInit } from '@angular/core';
import { EditorToolsService } from '../editor-tools.service';
import { ChangeBackgroundComponent } from '../tools/change-background/change-background.component';

@Component({
    selector: 'app-customize-section',
    templateUrl: './customize-section.component.html',
    styleUrls: ['./customize-section.component.css'],
    imports: [ChangeBackgroundComponent]
})
export class CustomizeSectionComponent implements OnInit {

    constructor(
        private editorTools: EditorToolsService
    ) { }

    ngOnInit(): void {
    }

    onBackClick() {
        this.editorTools.showEditPageTools()
    }
}
