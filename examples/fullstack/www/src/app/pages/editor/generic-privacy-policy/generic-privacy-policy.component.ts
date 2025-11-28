import { Component, OnInit } from '@angular/core';

@Component({
    selector: 'app-generic-privacy-policy',
    templateUrl: './generic-privacy-policy.component.html',
    styleUrls: ['./generic-privacy-policy.component.css'],
    standalone: true
})
export class GenericPrivacyPolicyComponent implements OnInit {

    effectiveDate = 'Jan 01, 2023'
    businessName = 'BUSINESS NAME'
    websiteName = 'WEBSITENAME.TLD'
    
    constructor() { }

    ngOnInit(): void {
    }

}
