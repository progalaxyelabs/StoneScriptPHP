import { NgFor, NgIf } from '@angular/common';
import { Component } from '@angular/core';
import { BsModalRef, BsModalService, ModalOptions } from 'ngx-bootstrap/modal';

@Component({
    selector: 'app-select-website-modal',
    imports: [NgIf, NgFor],
    templateUrl: './select-website-modal.component.html',
    styleUrl: './select-website-modal.component.css'
})
export class SelectWebsiteModalComponent {
    title?: string;
    closeBtnName?: string;
    list: string[] = [];

    constructor(
        public bsModalRef: BsModalRef
    ) {

    }

    ngOnInit() {
        this.list.push('PROFIT!!!');
    }

}
