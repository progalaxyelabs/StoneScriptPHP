import { EditorComponent } from './pages/editor/editor.component';
import { HomeComponent } from './pages/home/home.component';
import { NotFoundComponent } from './pages/not-found/not-found.component';
import { BlogContentComponent } from './pages/website-wizard/blog-content/blog-content.component';
import { BusinessContentComponent } from './pages/website-wizard/business-content/business-content.component';
import { EcommerceContentComponent } from './pages/website-wizard/ecommerce-content/ecommerce-content.component';
import { PortfolioContentComponent } from './pages/website-wizard/portfolio-content/portfolio-content.component';
import { Ecomm1Component } from './pages/ecomm1/ecomm1.component';
import { Routes } from '@angular/router';
import { WebsiteWizardComponent } from './pages/website-wizard/website-wizard.component';

export const routes: Routes = [
    { path: 'website-wizard', component: WebsiteWizardComponent },
    { path: 'website-wizard/portfolio-content', component: PortfolioContentComponent },
    { path: 'website-wizard/business-content', component: BusinessContentComponent },
    { path: 'website-wizard/ecommerce-content', component: EcommerceContentComponent },
    { path: 'website-wizard/blog-content', component: BlogContentComponent },
    { path: 'editor/:id', component: EditorComponent },
    // { path: 'ecomm1', component: Ecomm1Component, title: 'e-Commerce 1' },
    { path: '', pathMatch: 'full', component: HomeComponent },
    { path: '**', component: NotFoundComponent }
];
