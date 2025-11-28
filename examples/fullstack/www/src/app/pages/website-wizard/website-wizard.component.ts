import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Website, WebsiteService } from '../editor/website.service';
import { api, WebsitesRequest, WebsitesResponse } from '@stonescript/api-client';

@Component({
    selector: 'app-website-wizard',
    templateUrl: './website-wizard.component.html',
    styleUrls: ['./website-wizard.component.css'],
    imports: [FormsModule]
})
export class WebsiteWizardComponent implements OnInit {

    website: Website
    websiteTypeSelected = false
    tree: any = {
        branches: []
    }
    isSubmitting = false;
    errorMessage: string | null = null;

    constructor(
        private router: Router,
        private websiteService: WebsiteService
    ) {
        this.website = new Website('', 'portfolio', '')
    }

    ngOnInit(): void {
    }

    async onWebsiteTypeFormSubmit() {
        console.log('onWebsiteTypeFormSubmit')
        this.websiteTypeSelected = true
        this.isSubmitting = true;
        this.errorMessage = null;

        try {
            // Call the backend API to create website
            const request: WebsitesRequest = {
                name: this.website.name,
                type: this.website.type as 'portfolio' | 'business' | 'ecommerce' | 'blog'
            };

            const response: WebsitesResponse = await api.postWebsites(request);

            console.log('Website created successfully:', response);

            // Update local website with server response
            this.website.id = response.id;
            this.websiteService.addWebsite(this.website);

            // Navigate to editor
            this.router.navigateByUrl('/editor/' + response.id);

        } catch (error) {
            console.error('Failed to create website:', error);
            this.errorMessage = 'Failed to create website. Please try again.';
            this.websiteTypeSelected = false;
            this.isSubmitting = false;
        }

        // Original commented code for reference
        // switch (this.website.type) {
        //     case 'portfolio':
        //         this.router.navigateByUrl('/website-wizard/portfolio-content')
        //         break;
        //     case 'business':
        //         this.router.navigateByUrl('/website-wizard/business-content')
        //         break;
        //     case 'ecommerce':
        //         this.router.navigateByUrl('/website-wizard/ecommerce-content')
        //         break;
        //     case 'blog':
        //         this.router.navigateByUrl('/website-wizard/blog-content')
        //         break;
        //     default:
        //         this.router.navigateByUrl('/website-wizard/portfolio-content')
        //         break;
        // }
    }

}
